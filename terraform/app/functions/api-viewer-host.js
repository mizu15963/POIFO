// CloudFront Function（viewer-request）: 閲覧者がアクセスしたホスト名（api. / admin.）を
// x-poifo-viewer-host ヘッダーでオリジンに伝える。
// オリジンへの Host はオリジン名（origin.<domain>）になるため、nginx はこのヘッダーで api と admin を振り分ける。
// 閲覧者が同名のヘッダーを送ってきても、ここで必ず上書きされる。
function handler(event) {
  var request = event.request;
  request.headers['x-poifo-viewer-host'] = { value: request.headers.host.value.toLowerCase() };
  return request;
}
