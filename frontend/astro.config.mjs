// @ts-check
import { defineConfig } from 'astro/config';
import { loadEnv } from 'vite';

// .env（PUBLIC_SITE_URL）を設定ファイルでも読む
const { PUBLIC_SITE_URL } = loadEnv(process.env.NODE_ENV ?? 'production', process.cwd(), '');

// 静的サイトとして出力し、S3＋CloudFront で配信する。
// URL は「/campaigns/」のようなディレクトリ形式（CloudFront Function が /campaigns/index.html に読み替える）。
export default defineConfig({
  site: PUBLIC_SITE_URL || 'http://localhost:4321',
  output: 'static',
  trailingSlash: 'always',
  build: {
    format: 'directory',
  },
});
