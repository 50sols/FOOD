import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'package:http/http.dart' as http;

abstract class B2bApi {
  Future<Object?> get(String path);
}

abstract class B2bDocumentApi {
  Future<List<int>> getBytes(String path);
}

abstract class B2bDownloadApi {
  Future<B2bDownload> download(String path);
}

class B2bDownload {
  const B2bDownload({
    required this.bytes,
    required this.mimeType,
    required this.filename,
  });

  final Uint8List bytes;
  final String mimeType;
  final String filename;
}

class HttpB2bApi implements B2bApi, B2bDocumentApi, B2bDownloadApi {
  HttpB2bApi({
    required this.baseUrl,
    required this.token,
    this.retailStoreContextId,
    this.locale = 'ar',
    this.requestTimeout = const Duration(seconds: 20),
    this.maxGetAttempts = 2,
    http.Client? client,
  })  : assert(maxGetAttempts >= 1 && maxGetAttempts <= 3),
        _client = client ?? http.Client();

  final String baseUrl;
  final String token;
  final int? retailStoreContextId;
  final String locale;
  final Duration requestTimeout;
  final int maxGetAttempts;
  final http.Client _client;

  @override
  Future<List<int>> getBytes(String path) async {
    final response = await _getWithRetry(path, 'application/pdf');
    if (response.statusCode == 401 || response.statusCode == 403) {
      throw B2bApiException(
        'not_authorized',
        statusCode: response.statusCode,
        supportReference: _supportReference(response.headers),
      );
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw B2bApiException(
        'http_${response.statusCode}',
        statusCode: response.statusCode,
        supportReference: _supportReference(response.headers),
      );
    }
    // An expired session or reverse-proxy error can redirect a successful
    // HTTP GET to an HTML/JSON login page. Never share that response as an
    // invoice PDF: the server alone owns the actual document contents.
    final bytes = response.bodyBytes;
    final contentType =
        response.headers['content-type']?.split(';').first.trim().toLowerCase();
    final supportedType = contentType == null ||
        contentType.isEmpty ||
        contentType == 'application/pdf' ||
        contentType == 'application/octet-stream';
    if (!supportedType || !_hasPdfSignature(bytes)) {
      throw const B2bApiException('invalid_document');
    }
    return bytes;
  }

  @override
  Future<Object?> get(String path) async {
    final response = await _getWithRetry(path, 'application/json');
    if (response.statusCode == 401 || response.statusCode == 403) {
      throw B2bApiException(
        'not_authorized',
        statusCode: response.statusCode,
        supportReference: _supportReference(response.headers),
      );
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw B2bApiException(
        'http_${response.statusCode}',
        statusCode: response.statusCode,
        supportReference: _supportReference(response.headers),
      );
    }
    return response.body.isEmpty ? null : jsonDecode(response.body);
  }

  Future<http.Response> _getWithRetry(String path, String accept) async {
    Object? lastError;
    for (var attempt = 1; attempt <= maxGetAttempts; attempt++) {
      try {
        final response = await _client.get(
          Uri.parse('$baseUrl$path'),
          headers: {
            'Accept': accept,
            'Authorization': 'Bearer $token',
            'X-FOODEX-Customer-Domain': 'b2b',
            'Accept-Language': locale == 'en' ? 'en' : 'ar',
            if (retailStoreContextId != null)
              'X-FOODEX-Retail-Store-ID': retailStoreContextId.toString(),
          },
        ).timeout(requestTimeout);
        // These are safe invoice/statement/document reads, not financial
        // mutations. Keep the caller's session, store and locale on retry.
        if (attempt < maxGetAttempts &&
            (response.statusCode == 502 ||
                response.statusCode == 503 ||
                response.statusCode == 504)) {
          await Future<void>.delayed(Duration(milliseconds: 200 * attempt));
          continue;
        }
        return response;
      } on TimeoutException catch (error) {
        lastError = error;
      } on http.ClientException catch (error) {
        lastError = error;
      }

      if (attempt < maxGetAttempts) {
        await Future<void>.delayed(Duration(milliseconds: 200 * attempt));
      }
    }

    throw B2bApiException(
      lastError is TimeoutException ? 'timeout' : 'network_unavailable',
    );
  }

  @override
  Future<B2bDownload> download(String path) async {
    final response = await _getWithRetry(
      path,
      'application/pdf, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/octet-stream',
    );
    if (response.statusCode == 401 || response.statusCode == 403) {
      throw B2bApiException(
        'not_authorized',
        statusCode: response.statusCode,
        supportReference: _supportReference(response.headers),
      );
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw B2bApiException(
        'http_${response.statusCode}',
        statusCode: response.statusCode,
        supportReference: _supportReference(response.headers),
      );
    }

    final disposition = response.headers['content-disposition'] ?? '';
    final filenameMatch = RegExp(r'filename="?([^";]+)"?', caseSensitive: false)
        .firstMatch(disposition);
    final mime = response.headers['content-type']?.split(';').first.trim();
    return B2bDownload(
      bytes: response.bodyBytes,
      mimeType:
          mime == null || mime.isEmpty ? 'application/octet-stream' : mime,
      filename: filenameMatch?.group(1) ?? 'foodex-account-statement',
    );
  }
}

bool _hasPdfSignature(Uint8List bytes) =>
    bytes.length >= 5 &&
    bytes[0] == 0x25 &&
    bytes[1] == 0x50 &&
    bytes[2] == 0x44 &&
    bytes[3] == 0x46 &&
    bytes[4] == 0x2D;

class B2bApiException implements Exception {
  const B2bApiException(
    this.code, {
    this.statusCode,
    this.supportReference,
  });

  final String code;
  final int? statusCode;
  final String? supportReference;
}

String? _supportReference(Map<String, String> headers) {
  for (final key in const [
    'x-request-id',
    'x-correlation-id',
    'request-id',
    'traceparent',
  ]) {
    final value = headers[key];
    if (value != null && value.trim().isNotEmpty) {
      return value.trim();
    }
  }
  return null;
}
