<?php

// Simulates another request placing an order with a coupon, on its own database connection:
// locks the coupon row, reports it, waits, then commits one more use (and, for the "customer"
// mode, a placed order of the given customer with that coupon).
// Arguments: dsn user password couponId mode [channelId customerId number]

declare(strict_types=1);

$arguments = $_SERVER['argv'];
[, $dsn, $user, $password, $couponId, $mode] = $arguments;
$pdo = new PDO($dsn, $user, '' === $password ? null : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
$pdo->beginTransaction();
$pdo->prepare('SELECT used FROM sylius_promotion_coupon WHERE id = ? FOR UPDATE')->execute([$couponId]);
echo "locked\n";
flush();
sleep(2);
if ('customer' === $mode) {
    [, , , , , , $channelId, $customerId, $number] = $arguments;
    $pdo->prepare("INSERT INTO sylius_order (channel_id, customer_id, promotion_coupon_id, number, state, checkout_state, payment_state, shipping_state, currency_code, locale_code, total, items_total, adjustments_total, created_by_guest, created_at, updated_at, checkout_completed_at) VALUES (?, ?, ?, ?, 'new', 'completed', 'awaiting_payment', 'ready', 'USD', 'en_US', 1000, 1000, 0, 1, NOW(), NOW(), NOW())")
        ->execute([$channelId, $customerId, $couponId, $number]);
}
$pdo->prepare('UPDATE sylius_promotion_coupon SET used = used + 1, version = version + 1 WHERE id = ?')->execute([$couponId]);
$pdo->commit();
echo "committed\n";
