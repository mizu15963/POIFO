#!/usr/bin/env python3
"""IAM のシークレットアクセスキーから、SES の SMTP パスワードを計算する（AWS のドキュメントの手順どおり）。

使い方（サーバ上で。シークレットキーがシェルの履歴に残らないよう、標準入力から読む）:
    python3 ses-smtp-password.py            # プロンプトにシークレットキーを貼り付けて Enter
出力を .env の MAIL_PASSWORD に、アクセスキー ID を MAIL_USERNAME に入れる。
リージョンを変えるときは第1引数で（既定 ap-northeast-1）。
"""
import base64
import getpass
import hashlib
import hmac
import sys

DATE = "11111111"
SERVICE = "ses"
MESSAGE = "SendRawEmail"
TERMINAL = "aws4_request"
VERSION = 0x04


def sign(key: bytes, msg: str) -> bytes:
    return hmac.new(key, msg.encode("utf-8"), hashlib.sha256).digest()


def smtp_password(secret_access_key: str, region: str) -> str:
    signature = sign(("AWS4" + secret_access_key).encode("utf-8"), DATE)
    for part in (region, SERVICE, TERMINAL, MESSAGE):
        signature = sign(signature, part)
    return base64.b64encode(bytes([VERSION]) + signature).decode("utf-8")


if __name__ == "__main__":
    region = sys.argv[1] if len(sys.argv) > 1 else "ap-northeast-1"
    secret = getpass.getpass("シークレットアクセスキー: ").strip()
    if not secret:
        sys.exit("シークレットアクセスキーが空です")
    print(smtp_password(secret, region))
