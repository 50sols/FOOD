import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

class WholesaleCommerceException implements Exception {
  const WholesaleCommerceException(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}

abstract interface class WholesaleQuoteApi {
  Future<Object?> quote({
    required int storeId,
    String? couponCode,
    String? paymentMethod,
  });
}

abstract interface class WholesaleCommerceApi {
  Future<Object?> cart(int storeId);
  Future<Object?> addItem(int storeId, int productId, double quantity);
  Future<Object?> updateItem(int itemId, double quantity);
  Future<void> removeItem(int itemId);
  Future<Object?> checkout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? requestedDeliveryDate,
    String? note,
    String? couponCode,
    required String idempotencyKey,
  });
}

abstract interface class WholesaleSellingUnitCommerceApi {
  Future<Object?> addSellingUnitItem(
    int storeId,
    int productId,
    double quantity,
    String sellingUnitCode,
  );
}

class HttpWholesaleCommerceApi
    implements
        WholesaleCommerceApi,
        WholesaleQuoteApi,
        WholesaleSellingUnitCommerceApi {
  HttpWholesaleCommerceApi({
    required this.baseUrl,
    required this.token,
    this.retailStoreContextId,
    this.locale = 'ar',
    this.requestTimeout = const Duration(seconds: 20),
    this.maxGetAttempts = 2,
    http.Client? client,
  })  : assert(maxGetAttempts >= 1 && maxGetAttempts <= 3),
        assert(requestTimeout > Duration.zero),
        _client = client ?? http.Client();

  final String baseUrl;
  final String token;
  final int? retailStoreContextId;
  final String locale;
  final Duration requestTimeout;
  final int maxGetAttempts;
  final http.Client _client;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Authorization': 'Bearer $token',
        'X-FOODEX-Customer-Domain': 'b2b',
        'Accept-Language': locale == 'en' ? 'en' : 'ar',
        if (retailStoreContextId != null)
          'X-FOODEX-Retail-Store-ID': '$retailStoreContextId',
      };

  @override
  Future<Object?> cart(int storeId) async => _scopedResponse(
        await _request('GET', '/api/v1/cart', query: {'store': '$storeId'}),
        storeId: storeId,
      );

  @override
  Future<Object?> quote({
    required int storeId,
    String? couponCode,
    String? paymentMethod,
  }) async =>
      _scopedResponse(
        await _request(
          'POST',
          '/api/v1/quote',
          body: {
            'store_id': storeId,
            if (couponCode != null && couponCode.trim().isNotEmpty)
              'coupon_code': couponCode.trim().toUpperCase(),
            if (paymentMethod != null && paymentMethod.trim().isNotEmpty)
              'payment_method': paymentMethod.trim(),
          },
        ),
        storeId: storeId,
        wrapped: true,
      );

  @override
  Future<Object?> addItem(
    int storeId,
    int productId,
    double quantity,
  ) async =>
      _scopedResponse(
        await _request(
          'POST',
          '/api/v1/cart/items',
          body: {
            'store_id': storeId,
            'product_id': productId,
            'quantity': quantity,
          },
        ),
        storeId: storeId,
      );

  @override
  Future<Object?> addSellingUnitItem(
    int storeId,
    int productId,
    double quantity,
    String sellingUnitCode,
  ) async =>
      _scopedResponse(
        await _request(
          'POST',
          '/api/v1/cart/items',
          body: {
            'store_id': storeId,
            'product_id': productId,
            'quantity': quantity,
            'selling_unit_code': sellingUnitCode,
          },
        ),
        storeId: storeId,
      );

  @override
  Future<Object?> updateItem(int itemId, double quantity) => _request(
        'PATCH',
        '/api/v1/cart/items/$itemId',
        body: {'quantity': quantity},
      );

  @override
  Future<void> removeItem(int itemId) async {
    await _request('DELETE', '/api/v1/cart/items/$itemId');
  }

  @override
  Future<Object?> checkout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? requestedDeliveryDate,
    String? note,
    String? couponCode,
    required String idempotencyKey,
  }) async =>
      _scopedResponse(
        await _request(
          'POST',
          '/api/v1/checkout',
          body: {
            'store_id': storeId,
            'address_id': addressId,
            'payment_method': paymentMethod,
            if (requestedDeliveryDate != null)
              'requested_delivery_date': requestedDeliveryDate,
            if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
            if (couponCode != null && couponCode.trim().isNotEmpty)
              'coupon_code': couponCode.trim().toUpperCase(),
          },
          extraHeaders: {'Idempotency-Key': idempotencyKey},
        ),
        storeId: storeId,
        requireOrder: true,
      );

  Object? _scopedResponse(
    Object? raw, {
    required int storeId,
    bool wrapped = false,
    bool requireOrder = false,
  }) {
    final envelope = raw is Map<String, dynamic> ? raw : null;
    final value = wrapped && envelope != null ? envelope['data'] : envelope;
    // Transport-level integrity only: the server still decides prices,
    // discount approvals, credit eligibility and whether checkout committed.
    if (value is! Map<String, dynamic> ||
        value['store_id'] != storeId ||
        value['channel'] != 'b2b' ||
        (requireOrder && (value['id'] is! int || (value['id'] as int) <= 0))) {
      throw WholesaleCommerceException(
        locale == 'en'
            ? 'The server returned a different store or invalid order. Refresh and try again.'
            : 'أعاد الخادم بيانات متجر مختلف أو طلبًا غير صالح. حدّث الصفحة وحاول مرة أخرى.',
      );
    }
    return raw;
  }

  Future<Object?> _request(
    String method,
    String path, {
    Map<String, String> query = const {},
    Map<String, Object?>? body,
    Map<String, String> extraHeaders = const {},
  }) async {
    final base = Uri.parse('$baseUrl$path');
    final uri = query.isEmpty ? base : base.replace(queryParameters: query);
    final headers = {..._headers, ...extraHeaders};

    // Only GET is retry-safe. Quote and checkout are POST operations:
    // never silently resend a potentially committed business action.
    final attempts = method == 'GET' ? maxGetAttempts : 1;
    http.Response? response;
    for (var attempt = 1; attempt <= attempts; attempt++) {
      try {
        final Future<http.Response> pending;
        if (method == 'POST') {
          pending = _client.post(
            uri,
            headers: headers,
            body: jsonEncode(body ?? const {}),
          );
        } else if (method == 'PATCH') {
          pending = _client.patch(
            uri,
            headers: headers,
            body: jsonEncode(body ?? const {}),
          );
        } else if (method == 'DELETE') {
          pending = _client.delete(uri, headers: headers);
        } else {
          pending = _client.get(uri, headers: headers);
        }
        response = await pending.timeout(requestTimeout);
        // Safe reads may be retried on transient upstream HTTP failures.
        // Never replay quote, checkout, or cart mutations automatically.
        if (method == 'GET' &&
            attempt < attempts &&
            (response.statusCode == 502 ||
                response.statusCode == 503 ||
                response.statusCode == 504)) {
          await Future<void>.delayed(Duration(milliseconds: 200 * attempt));
          continue;
        }
        break;
      } on TimeoutException {
        if (attempt == attempts) {
          throw WholesaleCommerceException(
            locale == 'en'
                ? 'The server request timed out.'
                : 'انتهت مهلة الاتصال بالخادم.',
          );
        }
      } on http.ClientException {
        if (attempt == attempts) {
          throw WholesaleCommerceException(
            locale == 'en'
                ? 'Unable to connect to the server.'
                : 'تعذر الاتصال بالخادم.',
          );
        }
      }
      await Future<void>.delayed(Duration(milliseconds: 200 * attempt));
    }

    if (response == null) {
      throw WholesaleCommerceException(
        locale == 'en'
            ? 'Unable to complete the request.'
            : 'تعذر تنفيذ العملية.',
      );
    }

    Object? value;
    if (response.body.isNotEmpty) {
      try {
        value = jsonDecode(response.body);
      } catch (_) {
        value = null;
      }
    }

    if (response.statusCode < 200 || response.statusCode >= 300) {
      // Do not expose internal upstream error text or a foreign-store
      // authorization reason through the Customer interface.
      final String message;
      if (response.statusCode == 401) {
        message = locale == 'en'
            ? 'Your session has expired. Sign in again.'
            : 'انتهت صلاحية جلستك. سجّل الدخول مرة أخرى.';
      } else if (response.statusCode == 403) {
        message = locale == 'en'
            ? 'You do not have permission to perform this action.'
            : 'ليس لديك صلاحية لتنفيذ هذا الإجراء.';
      } else if (response.statusCode >= 500) {
        message = locale == 'en'
            ? 'The server is temporarily unavailable. Try again.'
            : 'الخادم غير متاح مؤقتًا. حاول مرة أخرى.';
      } else {
        final serverMessage = value is Map && value['message'] is String
            ? (value['message'] as String).trim()
            : '';
        message = serverMessage.isNotEmpty
            ? serverMessage
            : locale == 'en'
                ? 'Unable to complete the request.'
                : 'تعذر تنفيذ العملية.';
      }
      throw WholesaleCommerceException(
        message,
        statusCode: response.statusCode,
      );
    }

    // A successful cart, quote or checkout must carry an authoritative
    // JSON object. Do not reinterpret HTTP 200 HTML, malformed JSON, an
    // empty body or a JSON scalar as an empty cart/zero-price checkout.
    // Other cart mutations keep their existing response contract (including
    // the valid empty response on DELETE).
    if (method == 'GET' ||
        path == '/api/v1/quote' ||
        path == '/api/v1/checkout') {
      final mime = response.headers['content-type']
          ?.split(';')
          .first
          .trim()
          .toLowerCase();
      if (response.body.isEmpty ||
          (mime != null &&
              mime.isNotEmpty &&
              mime != 'application/json' &&
              !mime.endsWith('+json')) ||
          value is! Map<String, dynamic>) {
        throw WholesaleCommerceException(
          locale == 'en'
              ? 'The server returned an invalid response. Try again.'
              : 'أعاد الخادم استجابة غير صالحة. حاول مرة أخرى.',
        );
      }
    }
    return value;
  }
}
