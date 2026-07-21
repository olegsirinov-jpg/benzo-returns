<?php
/**
 * Front controller.
 * Система оформлення обміну та повернення товарів.
 */
declare(strict_types=1);

define('BASE_PATH', __DIR__);

require BASE_PATH . '/app/bootstrap.php';

$router = new App\Router();

// ---- Публічна частина ----
$router->get('/', function () {
    App\Response::redirect('/returns');
});
$router->get('/returns', 'PublicController@index');
$router->get('/returns/rules', 'PublicController@rules');
$router->get('/returns/new', 'PublicController@form');
$router->post('/returns/lookup', 'PublicController@lookup');
$router->post('/returns/submit', 'PublicController@submit');
$router->get('/returns/success', 'PublicController@success');
$router->get('/returns/status', 'PublicController@statusForm');
$router->post('/returns/status', 'PublicController@statusShow');
$router->post('/returns/ttn', 'PublicController@addTtn');

// ---- Авторизація ----
$router->get('/admin/login', 'AuthController@loginForm');
$router->post('/admin/login', 'AuthController@login');
$router->get('/admin/logout', 'AuthController@logout');

// ---- Адмінка ----
$router->get('/admin', 'AdminController@index');
$router->get('/admin/rma-new', 'AdminController@createForm');
$router->post('/admin/rma-new', 'AdminController@createSubmit');
$router->post('/admin/rma-lookup', 'AdminController@lookupOrder');
$router->get('/admin/rma/{id}', 'AdminController@show');
$router->post('/admin/rma/{id}/save', 'AdminController@save');
$router->post('/admin/rma/{id}/status', 'AdminController@changeStatus');
$router->post('/admin/rma/{id}/comment', 'AdminController@addComment');
$router->post('/admin/rma/{id}/photo', 'AdminController@addPhoto');
$router->post('/admin/rma/{id}/sms', 'AdminController@sendSms');
$router->get('/admin/rma/{id}/np-address', 'AdminController@npClientAddress');
$router->get('/admin/rma/{id}/np-price', 'AdminController@npPrice');
$router->post('/admin/rma/{id}/np-create', 'AdminController@npCreate');
$router->post('/admin/rma/{id}/np-cancel', 'AdminController@npCancel');
$router->post('/admin/rma/{id}/np-track', 'AdminController@npTrack');
$router->post('/admin/rma/{id}/delete', 'AdminController@delete');
$router->get('/admin/diag', 'AdminController@diag');
$router->post('/admin/diag', 'AdminController@diag');
$router->get('/admin/np-diag', 'AdminController@npDiag');
$router->post('/admin/np-diag', 'AdminController@npDiag');
$router->get('/admin/np/warehouses', 'AdminController@npWarehouses');
$router->get('/admin/export', 'AdminController@export');
$router->get('/admin/stats', 'AdminController@stats');
$router->get('/admin/users', 'AdminController@users');
$router->post('/admin/users', 'AdminController@saveUser');
$router->get('/admin/settings', 'AdminController@settings');
$router->post('/admin/settings', 'AdminController@saveSettings');
$router->post('/admin/settings/test', 'AdminController@testNotify');

$router->dispatch();
