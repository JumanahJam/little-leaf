<?php
session_start();
include_once("../config.php");
if (!isset($_SESSION['logged_in'])) { header("Location: ".BASE_URL."pages/login.php"); exit(); }

require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

include_once('db_connect.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    try {
        $pdo->beginTransaction();
        
        // 1. Fetch item from cart AND include 'stock_quantity' from the plants table
        $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.price, p.stock_quantity, c.quantity 
        FROM plants p 
        JOIN cart c ON p.id = c.plant_id 
        WHERE c.user_id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $cart_products = $stmt->fetchAll();

        // 2. Validate Stock AND Calculate Total
        $real_total = 0;
        foreach ($cart_products as $item) {
            // Check if user wants more than we have in stock
            if ($item['quantity'] > $item['stock_quantity']) {
                // Throwing an exception cancels the whole process and jumps to the 'catch' block
                throw new Exception("insufficient_stock"); 
            }
            $real_total += $item['price'] * $item['quantity'];
        }

        // 3. Create Order
        $stmt = $pdo->prepare("INSERT INTO orders (user_id, total_price, status) VALUES (?, ?, 'Processing')");
        $stmt->execute([$_SESSION['user_id'], $real_total]);
        $orderId = $pdo->lastInsertId();

        // 4. Insert Order Items AND Update Stock
        $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, plant_id, product_name, quantity, price_at_purchase) VALUES (?,?, ?, ?, ?)");
        $stockStmt = $pdo->prepare("UPDATE plants SET stock_quantity = stock_quantity - ? WHERE id = ?");

        foreach ($cart_products as $product) {
            // Insert the item into the order
            $itemStmt->execute([$orderId, $product['id'] ,$product['name'], $product['quantity'], $product['price']]);
            
            // Deduct the purchased quantity from the plants table
            $stockStmt->execute([$product['quantity'], $product['id']]);
        }

        // 5. CLEAR THE DATABASE CART (Crucial!)
        $clearStmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
        $clearStmt->execute([$_SESSION['user_id']]);

        $pdo->commit();

        // --- 6. EMAIL NOTIFICATION SYSTEM ---
        $emailErrorFlag = "";
        try {
            // Use first_name and last_name based on your DB screenshot
            $userStmt = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
            $userStmt->execute([$_SESSION['user_id']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($user && !empty($user['email'])) {
                $to = $user['email'];
                $subject = "Order Confirmation - Little Leaf Sanctuary";
                
                $customerName = $user['first_name'] . ' ' . $user['last_name'];
                $message = "Hello " . $customerName . ",\n\n";
                $message .= "Thank you for your order! Your sanctuary is about to get greener.\n\n";
                $message .= "--- ORDER SUMMARY ---\n";
                $message .= "Order ID: #" . $orderId . "\n";
                foreach ($cart_products as $product) {
                    $message .= "• " . $product['name'] . " (Qty: " . $product['quantity'] . ") - " . ($product['price'] * $product['quantity']) . " SAR\n";
                }
                $message .= "---------------------\n";
                $message .= "Total: " . $real_total . " SAR\n\n";
                $message .= "We are preparing your plants and will notify you when they ship.\n\n";
                $message .= "Best regards,\nThe Little Leaf Team";

                $headers = "From: noreply@littleleaf.com\r\n";
                $headers .= "Reply-To: support@littleleaf.com\r\n";

                try {

    $userStmt = $pdo->prepare("
        SELECT email, first_name, last_name
        FROM users
        WHERE id = ?
    ");

    $userStmt->execute([$_SESSION['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {

        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;

        $mail->Username = 'juju.jamal.a.b@gmail.com';
        $mail->Password = 'ykrp ttjt cgsf phui';

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom(
            'juju.jamal.a.b@gmail.com',
            'Little Leaf'
        );

        $mail->addAddress($user['email']);

        $mail->isHTML(true);

        $mail->Subject = 'Order Confirmation #' . $orderId;

        $customerName =
            $user['first_name'] . ' ' . $user['last_name'];

        $body = "
        <h2>Thank you for your order!</h2>

        <p>Hello {$customerName},</p>

        <p>Your order has been successfully placed.</p>

        <p><strong>Order Number:</strong> {$orderId}</p>

        <p><strong>Total:</strong> {$real_total} SAR</p>

        <p>We are preparing your plants.</p>

        <p>Little Leaf Team</p>
        ";

        $mail->Body = $body;

        $mail->send();
    }

} catch (Exception $e) {

    error_log(
        'Email Error: ' . $mail->ErrorInfo
    );
}
            }
        } catch (Exception $emailEx) {
            $emailErrorFlag = "&email_err=failed";
        }

        // 7. Redirect to profile with success status
        header("Location: " . BASE_URL  . "pages/profile.php?status=order" . $emailErrorFlag);
        exit;

    } catch (Exception $e) {
        // If anything fails (like stock check), roll back all database changes
        $pdo->rollBack();
        
        // Redirect back to cart based on the specific error
        if ($e->getMessage() === 'insufficient_stock') {
            header("Location: " . BASE_URL . "pages/cart.php?error=out_of_stock");
        } else {
            header("Location: " . BASE_URL . "pages/cart.php?error=fail_order");
        }
        exit;
    }
}
?>

/*session_start();
include_once("../config.php");
if (!isset($_SESSION['logged_in'])) { header("Location: ".BASE_URL."pages/login.php"); exit(); }

include_once('db_connect.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    try {
        $address_id = $_POST['address_id'] ?? null;
        $payment_method_id = $_POST['payment_method_id'] ?? null;

        $pdo->beginTransaction();
        
        // 1. Fetch item from cart AND include 'stock_quantity' from the plants table
        $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.price, p.stock_quantity, c.quantity 
        FROM plants p 
        JOIN cart c ON p.id = c.plant_id 
        WHERE c.user_id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $cart_products = $stmt->fetchAll();

        // 2. Validate Stock AND Calculate Total
        $real_total = 0;
        foreach ($cart_products as $item) {
            // Check if user wants more than we have in stock
            if ($item['quantity'] > $item['stock_quantity']) {
                // Throwing an exception cancels the whole process and jumps to the 'catch' block
                throw new Exception("insufficient_stock"); 
            }
            $real_total += $item['price'] * $item['quantity'];
        }

        // 3. Create Order
        $stmt = $pdo->prepare("INSERT INTO orders (user_id, total_price, status, address_id, payment_method_id) VALUES (?, ?, 'Processing', ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $real_total, $address_id, $payment_method_id]);
        $orderId = $pdo->lastInsertId();

        // 4. Insert Order Items AND Update Stock
        $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, plant_id, product_name, quantity, price_at_purchase) VALUES (?,?, ?, ?, ?)");
        $stockStmt = $pdo->prepare("UPDATE plants SET stock_quantity = stock_quantity - ? WHERE id = ?");

        foreach ($cart_products as $product) {
            // Insert the item into the order
            $itemStmt->execute([$orderId, $product['id'] ,$product['name'], $product['quantity'], $product['price']]);
            
            // Deduct the purchased quantity from the plants table
            $stockStmt->execute([$product['quantity'], $product['id']]);
        }

        // 5. CLEAR THE DATABASE CART (Crucial!)
        $clearStmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
        $clearStmt->execute([$_SESSION['user_id']]);

        $pdo->commit();

        // --- 6. EMAIL NOTIFICATION SYSTEM ---
        $emailErrorFlag = "";
        try {
            // Use first_name and last_name based on your DB screenshot
            $userStmt = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
            $userStmt->execute([$_SESSION['user_id']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($user && !empty($user['email'])) {
                $to = $user['email'];
                $subject = "Order Confirmation - Little Leaf Sanctuary";
                
                $customerName = $user['first_name'] . ' ' . $user['last_name'];
                $message = "Hello " . $customerName . ",\n\n";
                $message .= "Thank you for your order! Your sanctuary is about to get greener.\n\n";
                $message .= "--- ORDER SUMMARY ---\n";
                $message .= "Order ID: #" . $orderId . "\n";
                foreach ($cart_products as $product) {
                    $message .= "• " . $product['name'] . " (Qty: " . $product['quantity'] . ") - " . ($product['price'] * $product['quantity']) . " SAR\n";
                }
                $message .= "---------------------\n";
                $message .= "Total: " . $real_total . " SAR\n\n";
                $message .= "We are preparing your plants and will notify you when they ship.\n\n";
                $message .= "Best regards,\nThe Little Leaf Team";

                $headers = "From: noreply@littleleaf.com\r\n";
                $headers .= "Reply-To: support@littleleaf.com\r\n";

                if (!@mail($to, $subject, $message, $headers)) {
                    $emailErrorFlag = "&email_err=failed";
                }
            }
        } catch (Exception $emailEx) {
            $emailErrorFlag = "&email_err=failed";
        }

        // 7. Redirect to profile with success status
        header("Location: " . BASE_URL  . "pages/profile.php?status=order" . $emailErrorFlag);
        exit;

    } catch (Exception $e) {
        // If anything fails (like stock check), roll back all database changes
        $pdo->rollBack();
        
        // Redirect back to cart based on the specific error
        if ($e->getMessage() === 'insufficient_stock') {
            header("Location: " . BASE_URL . "pages/cart.php?error=out_of_stock");
        } else {
            header("Location: " . BASE_URL . "pages/cart.php?error=fail_order");
        }
        exit;
    }
}*/
