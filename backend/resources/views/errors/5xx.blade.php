{{-- 個別の画面がない 500 系（502・504 など） --}}
@extends('errors.layout', ['code' => $exception->getStatusCode(), 'heading' => 'ただいまつながりにくくなっています', 'message' => 'ご不便をおかけして申し訳ありません。時間をおいて、もう一度お試しください。'])
