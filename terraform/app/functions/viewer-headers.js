// CloudFront Function（viewer-request）: 閲覧者の情報をヘッダーでオリジンに伝える。API 用と管理画面用の CloudFront で1つずつ作る。
//   x-poifo-viewer-host: その CloudFront の別名（api.<domain> / admin.<domain>）。Terraform が埋め込む固定値。
//     オリジンへの Host はオリジン名（origin.<domain>）になるため、nginx・アプリはこのヘッダーで api と admin を振り分ける。
//     閲覧者が送った Host ヘッダーは使わない（API 用の CloudFront 経由で admin. のルートに入り、管理画面用の WAF の IP 制限を避けられないように）。
//   x-poifo-viewer-ip: 閲覧者の IP アドレス（ポートなし。IPv6 もそのまま）。
//     ポストバックの送信元確認や回数制限に使う。LB を挟んでも変わらない。
// 閲覧者が同名のヘッダーを送ってきても、ここで必ず上書きされる。
function handler(event) {
  var request = event.request;
  request.headers['x-poifo-viewer-host'] = { value: '${viewer_host}' };
  request.headers['x-poifo-viewer-ip'] = { value: event.viewer.ip };
  return request;
}
