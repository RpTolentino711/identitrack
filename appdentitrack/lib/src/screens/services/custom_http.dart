import 'package:http/http.dart' as original_http;

export 'package:http/http.dart' show Response, MultipartFile;

Future<original_http.Response> post(Uri url, {Map<String, String>? headers, Object? body}) async {
  try {
    return await original_http.post(url, headers: headers, body: body);
  } catch (e) {
    throw mapException(e);
  }
}

class MultipartRequest extends original_http.MultipartRequest {
  MultipartRequest(super.method, super.url);

  @override
  Future<original_http.StreamedResponse> send() async {
    try {
      return await super.send();
    } catch (e) {
      throw mapException(e);
    }
  }
}

Object mapException(Object e) {
  final str = e.toString().toLowerCase();
  if (str.contains('socketexception') ||
      str.contains('clientexception') ||
      str.contains('failed host lookup') ||
      str.contains('connection refused') ||
      str.contains('network is unreachable') ||
      str.contains('connection timed out') ||
      str.contains('handshakeexception')) {
    return Exception('Please connect to Wi-Fi / Internet to load your discipline record.');
  }
  return e;
}
