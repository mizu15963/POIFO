# CLAUDE.md

backend/ の作業ルールは AGENTS.md にまとめています（Claude 以外のエージェントと共通）。必ず読んで従ってください。

@AGENTS.md

## Claude Code 向けの補足

- リポジトリ全体の前提（public リポジトリ、秘密情報を書かない）は [../README.md](../README.md) も参照。
- 作業後は `php artisan test` と `./vendor/bin/pint --test` を実行し、結果を報告する。失敗したテストは失敗したものだけ再実行して直す。
