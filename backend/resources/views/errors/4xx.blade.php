{{-- 個別の画面がない 400 系（400・405 など）。Laravel 標準の英語の画面より先にこちらが使われる --}}
@extends('errors.layout', ['code' => $exception->getStatusCode(), 'heading' => 'ページを表示できません', 'message' => 'URLや操作が正しくない可能性があります。お手数ですが、トップからやり直してください。'])
