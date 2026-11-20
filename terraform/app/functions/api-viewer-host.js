// CloudFront Function（viewer-request）: 閲覧者の情報をヘッダーでオリジンに伝える。
//   x-poifo-viewer-host: 閲覧者がアクセスしたホスト名（api. / admin.）。
//     オリジンへの Host はオリジン名（origin.<domain>）になるため、nginx・アプリはこのヘッダーで api と admin を振り分ける。
//   x-poifo-viewer-ip: 閲覧者の IP アドレス（ポートなし。IPv6 もそのまま）。
//     ポストバックの送信元確認や回数制限に使う。LB を挟んでも変わらない。
// 閲覧者が同名のヘッダーを送ってきても、ここで必ず上書きされる。
function handler(event) {
  var request = event.request;
  request.headers['x-poifo-viewer-host'] = { value: request.headers.host.value.toLowerCase() };
  request.headers['x-poifo-viewer-ip'] = { value: event.viewer.ip };
  return request;
}
