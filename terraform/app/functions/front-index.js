// CloudFront Function（viewer-request）: ディレクトリ形式の URL を index.html に読み替える
//   /           → /index.html（default_root_object でも処理される）
//   /about/     → /about/index.html
//   /about      → /about/index.html（拡張子がないパス）
function handler(event) {
  var request = event.request;
  var uri = request.uri;
  if (uri.endsWith('/')) {
    request.uri = uri + 'index.html';
  } else if (uri.split('/').pop().indexOf('.') === -1) {
    request.uri = uri + '/index.html';
  }
  return request;
}
