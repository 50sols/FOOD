import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test(
      'Invoice detail GET retries only transient HTTP failures with stable scope',
      () async {
    for (final code in [502, 503, 504]) {
      var attempts = 0;
      final api = HttpB2bApi(
        baseUrl: 'https://foodex.example',
        token: 'opaque-token',
        retailStoreContextId: 17,
        locale: 'en',
        maxGetAttempts: 2,
        client: MockClient((request) async {
          attempts++;
          expect(request.method, 'GET');
          expect(request.url.path, '/api/v1/b2b/invoices/31');
          expect(request.url.queryParameters['store_id'], '7');
          expect(request.headers['Authorization'], 'Bearer opaque-token');
          expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
          expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
          expect(request.headers['Accept-Language'], 'en');
          expect(request.headers['Accept'], 'application/json');
          if (attempts == 1) {
            return http.Response('{"message":"upstream transient"}', code);
          }
          return http.Response(
            '{"data":{"id":31,"payments":[{"status":"captured","amount":20.125,"currency":"KWD"}]}}',
            200,
          );
        }),
      );

      final result = await api.get('/api/v1/b2b/invoices/31?store_id=7');
      expect(attempts, 2);
      expect(
        (result as Map<String, dynamic>)['data'],
        {
          'id': 31,
          'payments': [
            {'status': 'captured', 'amount': 20.125, 'currency': 'KWD'},
          ],
        },
      );
    }
  });

  test('B2B GET 503 exhaustion retains status and safe support reference',
      () async {
    for (final language in ['ar', 'en']) {
      var attempts = 0;
      final api = HttpB2bApi(
        baseUrl: 'https://foodex.example',
        token: 'opaque-token',
        locale: language,
        maxGetAttempts: 2,
        client: MockClient((request) async {
          attempts++;
          expect(request.headers['Accept-Language'], language);
          return http.Response(
            '{"message":"internal stack trace"}',
            503,
            headers: {'x-request-id': 'safe-reference-503'},
          );
        }),
      );

      await expectLater(
        api.get('/api/v1/b2b/account-statement?store_id=7'),
        throwsA(
          isA<B2bApiException>()
              .having((error) => error.code, 'code', 'http_503')
              .having((error) => error.statusCode, 'status', 503)
              .having(
                (error) => error.supportReference,
                'support reference',
                'safe-reference-503',
              ),
        ),
      );
      expect(attempts, 2);
    }
  });

  test('B2B invoice and ledger GET deny 401/403 without replay', () async {
    for (final language in ['ar', 'en']) {
      for (final status in [401, 403]) {
        var attempts = 0;
        final api = HttpB2bApi(
          baseUrl: 'https://foodex.example',
          token: 'opaque-token',
          locale: language,
          retailStoreContextId: 17,
          maxGetAttempts: 3,
          client: MockClient((request) async {
            attempts++;
            expect(request.headers['Accept-Language'], language);
            expect(request.headers['Authorization'], 'Bearer opaque-token');
            expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
            return http.Response('{"message":"scope denied"}', status);
          }),
        );

        await expectLater(
          api.get('/api/v1/b2b/account-statement?store_id=7'),
          throwsA(
            isA<B2bApiException>()
                .having((error) => error.code, 'code', 'not_authorized')
                .having((error) => error.statusCode, 'status', status),
          ),
        );
        expect(attempts, 1);
      }
    }
  });

  test('B2B statement PDF retries a safe GET with locale and authorized scope',
      () async {
    var attempts = 0;
    final api = HttpB2bApi(
      baseUrl: 'https://foodex.example',
      token: 'opaque-token',
      locale: 'en',
      retailStoreContextId: 17,
      maxGetAttempts: 2,
      client: MockClient((request) async {
        attempts++;
        expect(request.method, 'GET');
        expect(request.url.path, '/api/v1/b2b/account-statement/export');
        expect(request.url.queryParameters['store_id'], '7');
        expect(request.headers['Authorization'], 'Bearer opaque-token');
        expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
        expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
        expect(request.headers['Accept-Language'], 'en');
        expect(request.headers['Accept'], contains('application/pdf'));
        if (attempts == 1) {
          return http.Response('{"message":"gateway"}', 502);
        }
        return http.Response(
          '%PDF-1.4',
          200,
          headers: {
            'content-type': 'application/pdf',
            'content-disposition': 'attachment; filename="statement.pdf"',
          },
        );
      }),
    );

    final file = await api.download(
      '/api/v1/b2b/account-statement/export?store_id=7',
    );
    expect(attempts, 2);
    expect(file.mimeType, 'application/pdf');
    expect(file.filename, 'statement.pdf');
    expect(file.bytes, isNotEmpty);
  });

  test('B2B statement XLSX passes scoped AR/EN binary transport', () async {
    for (final language in ['ar', 'en']) {
      var calls = 0;
      final api = HttpB2bApi(
        baseUrl: 'https://foodex.example',
        token: 'opaque-token',
        retailStoreContextId: 17,
        locale: language,
        maxGetAttempts: 1,
        client: MockClient((request) async {
          calls++;
          expect(request.url.queryParameters['format'], 'xlsx');
          expect(request.url.queryParameters['store_id'], '7');
          expect(request.headers['Authorization'], 'Bearer opaque-token');
          expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
          expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
          expect(request.headers['Accept-Language'], language);
          return http.Response.bytes(
            [0x50, 0x4b, 0x03, 0x04, 0x00],
            200,
            headers: {
              'content-type':
                  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
              'content-disposition': 'attachment; filename="statement.xlsx"',
            },
          );
        }),
      );
      final file = await api.download(
        '/api/v1/b2b/account-statement/export?store_id=7&format=xlsx',
      );
      expect(calls, 1);
      expect(file.filename, 'statement.xlsx');
      expect(file.mimeType,
          'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
  });

  test('B2B statement export rejects 200 wrong MIME and corrupt bytes',
      () async {
    final cases = <({String format, String mime, String body})>[
      (format: 'pdf', mime: 'text/html', body: '<html>Login</html>'),
      (format: 'pdf', mime: 'application/json', body: '{"error":"denied"}'),
      (format: 'pdf', mime: 'application/pdf', body: ''),
      (format: 'pdf', mime: 'application/pdf', body: 'not-pdf'),
      (format: 'xlsx', mime: 'text/html', body: '<html>Login</html>'),
      (
        format: 'xlsx',
        mime:
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        body: 'not-xlsx'
      ),
      (format: 'xlsx', mime: 'application/pdf', body: '%PDF-1.4'),
    ];
    for (final language in ['ar', 'en']) {
      for (final failure in cases) {
        var calls = 0;
        final api = HttpB2bApi(
          baseUrl: 'https://foodex.example',
          token: 'opaque-token',
          locale: language,
          retailStoreContextId: 17,
          maxGetAttempts: 3,
          client: MockClient((request) async {
            calls++;
            expect(request.headers['Authorization'], 'Bearer opaque-token');
            expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
            expect(request.headers['Accept-Language'], language);
            return http.Response(failure.body, 200,
                headers: {'content-type': failure.mime});
          }),
        );
        await expectLater(
          api.download(
            '/api/v1/b2b/account-statement/export?store_id=7&format=' +
                failure.format,
          ),
          throwsA(isA<B2bApiException>()
              .having((error) => error.code, 'code', 'invalid_document')),
        );
        expect(calls, 1);
      }
    }
  });

  test('B2B statement export rejects malicious filenames', () async {
    final api = HttpB2bApi(
      baseUrl: 'https://foodex.example',
      token: 'opaque-token',
      maxGetAttempts: 1,
      client: MockClient((_) async => http.Response(
            '%PDF-1.4',
            200,
            headers: {
              'content-type': 'application/octet-stream',
              'content-disposition': 'attachment; filename="../../data.exe"',
            },
          )),
    );
    final file = await api.download(
      '/api/v1/b2b/account-statement/export?format=pdf',
    );
    expect(file.mimeType, 'application/pdf');
    expect(file.filename, 'foodex-account-statement.pdf');
  });

  test('B2B statement export 401/403 denies without retry', () async {
    for (final language in ['ar', 'en']) {
      for (final status in [401, 403]) {
        var calls = 0;
        final api = HttpB2bApi(
          baseUrl: 'https://foodex.example',
          token: 'opaque-token',
          locale: language,
          maxGetAttempts: 3,
          client: MockClient((request) async {
            calls++;
            expect(request.headers['Accept-Language'], language);
            return http.Response('<html>Denied</html>', status);
          }),
        );
        await expectLater(
          api.download('/api/v1/b2b/account-statement/export?format=pdf'),
          throwsA(isA<B2bApiException>()
              .having((error) => error.code, 'code', 'not_authorized')
              .having((error) => error.statusCode, 'status', status)),
        );
        expect(calls, 1);
      }
    }
  });

  test(
      'B2B GET retries one transient client failure and preserves retail context',
      () async {
    var attempts = 0;
    final api = HttpB2bApi(
      baseUrl: 'https://foodex.example',
      token: 'opaque-token',
      retailStoreContextId: 17,
      maxGetAttempts: 2,
      client: MockClient((request) async {
        attempts++;
        expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
        expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
        if (attempts == 1) {
          throw http.ClientException('temporary disconnect', request.url);
        }
        return http.Response('{"data":[]}', 200);
      }),
    );

    final result = await api.get('/api/v1/b2b/account-statement');

    expect(attempts, 2);
    expect(result, isA<Map>());
  });

  test('B2B GET stops after bounded attempts on network failure', () async {
    var attempts = 0;
    final api = HttpB2bApi(
      baseUrl: 'https://foodex.example',
      token: 'opaque-token',
      maxGetAttempts: 2,
      client: MockClient((request) async {
        attempts++;
        throw http.ClientException('offline', request.url);
      }),
    );

    await expectLater(
      api.get('/api/v1/b2b/account-statement'),
      throwsA(
        isA<B2bApiException>().having(
          (error) => error.code,
          'code',
          'network_unavailable',
        ),
      ),
    );
    expect(attempts, 2);
  });

  test('B2B GET timeout is bounded and reported distinctly', () async {
    final api = HttpB2bApi(
      baseUrl: 'https://foodex.example',
      token: 'opaque-token',
      requestTimeout: const Duration(milliseconds: 5),
      maxGetAttempts: 1,
      client: MockClient((_) async {
        await Future<void>.delayed(const Duration(milliseconds: 50));
        return http.Response('{}', 200);
      }),
    );

    await expectLater(
      api.get('/api/v1/b2b/account-statement'),
      throwsA(
        isA<B2bApiException>().having(
          (error) => error.code,
          'code',
          'timeout',
        ),
      ),
    );
  });

  test(
      'B2B invoice PDF retries bounded GET with session, locale and store scope',
      () async {
    for (final locale in ['ar', 'en']) {
      var attempts = 0;
      final pdf = <int>[37, 80, 68, 70, 45, 49, 46, 55, 10];
      final api = HttpB2bApi(
        baseUrl: 'https://foodex.example',
        token: 'pdf-session-token',
        retailStoreContextId: 17,
        locale: locale,
        maxGetAttempts: 2,
        client: MockClient((request) async {
          attempts++;
          expect(request.method, 'GET');
          expect(request.url.path, '/api/v1/b2b/invoices/31/download');
          expect(request.url.queryParameters['store_id'], '7');
          expect(request.headers['Authorization'], 'Bearer pdf-session-token');
          expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
          expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
          expect(request.headers['Accept-Language'], locale);
          expect(request.headers['Accept'], 'application/pdf');
          if (attempts == 1) {
            return http.Response('temporary gateway', 502);
          }
          return http.Response.bytes(
            pdf,
            200,
            headers: {'content-type': 'application/pdf; charset=binary'},
          );
        }),
      );

      final bytes =
          await api.getBytes('/api/v1/b2b/invoices/31/download?store_id=7');
      expect(bytes, orderedEquals(pdf));
      expect(attempts, 2);
    }
  });

  test('B2B invoice PDF fails closed on 200 login HTML, JSON and corrupt bytes',
      () async {
    final cases = <({String mime, String body})>[
      (mime: 'text/html', body: '<html>Sign in</html>'),
      (mime: 'application/json', body: '{"message":"login required"}'),
      (mime: 'application/pdf', body: 'not a PDF'),
      (mime: 'application/pdf', body: ''),
      (mime: 'text/html', body: '%PDF-1.7'),
    ];
    for (final response in cases) {
      for (final locale in ['ar', 'en']) {
        var attempts = 0;
        final api = HttpB2bApi(
          baseUrl: 'https://foodex.example',
          token: 'pdf-session-token',
          retailStoreContextId: 17,
          locale: locale,
          maxGetAttempts: 3,
          client: MockClient((request) async {
            attempts++;
            expect(request.method, 'GET');
            expect(request.url.queryParameters['store_id'], '7');
            expect(
                request.headers['Authorization'], 'Bearer pdf-session-token');
            expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
            expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
            expect(request.headers['Accept-Language'], locale);
            return http.Response(
              response.body,
              200,
              headers: {'content-type': response.mime},
            );
          }),
        );

        await expectLater(
          api.getBytes('/api/v1/b2b/invoices/31/download?store_id=7'),
          throwsA(
            isA<B2bApiException>()
                .having((error) => error.code, 'code', 'invalid_document'),
          ),
        );
        expect(attempts, 1);
      }
    }
  });

  test('B2B invoice PDF preserves 401/403 denial without retry', () async {
    for (final status in [401, 403]) {
      var attempts = 0;
      final api = HttpB2bApi(
        baseUrl: 'https://foodex.example',
        token: 'expired-token',
        retailStoreContextId: 17,
        maxGetAttempts: 3,
        client: MockClient((request) async {
          attempts++;
          expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
          expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
          return http.Response(
            '<html>login</html>',
            status,
            headers: {'content-type': 'text/html'},
          );
        }),
      );

      await expectLater(
        api.getBytes('/api/v1/b2b/invoices/31/download?store_id=7'),
        throwsA(
          isA<B2bApiException>()
              .having((error) => error.code, 'code', 'not_authorized')
              .having((error) => error.statusCode, 'status', status),
        ),
      );
      expect(attempts, 1);
    }
  });
}
