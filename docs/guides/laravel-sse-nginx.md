# Laravel: streaming behind nginx and PHP-FPM

`SendStreamingMessage` and `SubscribeToTask` answer with Server-Sent Events. Each event must reach the client the moment the agent publishes it, so nothing between PHP and the client may buffer the response.

## nginx

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;

    fastcgi_buffering off;       # or rely on the X-Accel-Buffering: no header the SDK sends
    fastcgi_read_timeout 3600s;  # streams stay open while the task runs
}
```

Behind a load balancer or CDN, turn response buffering off there too (and raise its idle timeout above `a2a.sse.keep_alive`).

## PHP

- The SDK closes PHP's own output buffers and flushes after every event, so `output_buffering` in `php.ini` is handled.
- `zlib.output_compression` must be off for these routes (it buffers).
- `max_execution_time` doesn't apply to the time spent waiting, but set `request_terminate_timeout` in the FPM pool above your longest stream.

## Every open stream holds a PHP-FPM worker

This is the main difference from Python, where one process serves thousands of streams. Size `pm.max_children` for your peak number of open streams plus normal traffic, and use the queued runner so the work itself runs on queue workers instead of FPM workers.

Two settings protect the pool from clients that never hang up:

```php
// config/a2a.php
'sse' => [
    'keep_alive' => 15.0,  // seconds between keep-alive comments
    'max_idle'   => 600,   // end a SubscribeToTask stream after this long without events
],
```

PHP only notices that a client has gone when it writes to the connection, so `keep_alive` is also how quickly an abandoned stream frees its worker.

!!! warning "Don't serve streams with `php artisan serve`"
    PHP's built-in server can accept a request on a worker that is busy streaming, and that request then waits for the whole stream. Fine for a quick try; for anything with several clients use PHP-FPM.
