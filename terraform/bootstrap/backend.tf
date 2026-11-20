# bootstrap の state も、自分が作る state バケットの bootstrap/terraform.tfstate に置く
# （手元PCが壊れたときの保険と、ほかの人への引き継ぎのため。バージョニングで過去の state も残る）。
# バケットはこのスタックが作るので、初回だけはこのファイルを外して手元で apply し、その後 state を移す
# （手順は docs/infra/README.md の「2. bootstrap を手元PCで apply」）。
# bucket と region は init 時に -backend-config で渡す（バケット名にアカウント ID が入るため）
terraform {
  backend "s3" {
    key          = "bootstrap/terraform.tfstate"
    use_lockfile = true
    encrypt      = true
  }
}
