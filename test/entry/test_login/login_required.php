<?php
/**
 * login要求のaction
 */
$b = new \testman\Browser();

// loginにリダイレクトされる
$b->do_get(test_map_url('test_login::bbb'));
eq(401,$b->status());
eq(test_map_url('test_login::login'),$b->url());
eq('{"error":[{"message":"Unauthorized","group":"","type":"UnauthorizedException"}]}',$b->body());

// ログインしたらbbbに戻る
$b->vars('user','tokushima');
$b->vars('password','hogehoge');
$b->do_post(test_map_url('test_login::login'));
eq(test_map_url('test_login::bbb'),$b->url());
eq(200,$b->status());

// ログイン済みならリダイレクトされない
eq(test_map_url('test_login::bbb'),$b->url());
eq(200,$b->status());
meq('{"result":{"abc":123}}',$b->body());

// ログアウト
$b->do_get(test_map_url('test_login::logout'));

// 最初からログインならlogin_redirectに従う
$b->vars('user','tokushima');
$b->vars('password','hogehoge');
$b->do_post(test_map_url('test_login::login'));
eq(test_map_url('test_login::aaa'),$b->url());
eq(200,$b->status());

