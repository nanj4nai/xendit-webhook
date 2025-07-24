<?php

require_once "db.php";
require __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. Parse webhook input
$input = file_get_contents("php://input");
$data = json_decode($input, true);

// Log raw webhook data
file_put_contents("webhook_log.txt", date("Y-m-d H:i:s") . " - " . $input . PHP_EOL, FILE_APPEND);

// 2. Verify Xendit token
$config = require 'config.php';
$expectedToken = $config['xendit_webhook_token'] ?? '';
$callbackToken = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '';

file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - Received callback token: $callbackToken\n", FILE_APPEND);

if ($callbackToken !== $expectedToken) {
  file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - Invalid callback token\n", FILE_APPEND);
  http_response_code(403);
  echo "❌ Invalid callback token";
  exit;
}

// 3. Validate payload
if (!isset($data['id'], $data['status'], $data['external_id'])) {
  file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - Missing required fields in payload\n", FILE_APPEND);
  http_response_code(400);
  echo "❌ Missing invoice ID, status or external_id";
  exit;
}

$invoiceId = $data['id'];
$paymentStatus = strtolower($data['status']);

// 4. Extract booking ID
if (preg_match('/booking_(\d+)/', $data['external_id'], $matches)) {
  $bookingId = (int)$matches[1];
  file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - Extracted booking ID: $bookingId\n", FILE_APPEND);
} else {
  file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - Could not extract booking ID from external_id: {$data['external_id']}\n", FILE_APPEND);
  http_response_code(400);
  echo "❌ Invalid external_id format";
  exit;
}
// 5. Fetch booking data
$stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = ?");
$stmt->execute([$bookingId]);
$bookingData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bookingData) {
  file_put_contents("webhook_debug.txt", "Booking not found: $bookingId\n", FILE_APPEND);
  http_response_code(404);
  exit("Booking not found");
}

// 👇 ADD this to prevent undefined $booking
$booking = $bookingData;

// 6. Fetch room data
$stmt = $pdo->prepare("SELECT room_name, price FROM rooms WHERE id = ?");
$stmt->execute([$bookingData['room_id']]);
$roomData = $stmt->fetch(PDO::FETCH_ASSOC);

// 👇 ADD this to prevent undefined $room
$room = $roomData;

// 7. Fetch payment record
$stmt = $pdo->prepare("SELECT * FROM payments WHERE booking_id = ? AND xendit_invoice_id = ?");
$stmt->execute([$bookingId, $invoiceId]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$payment) {
  file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - Payment not found\n", FILE_APPEND);
  http_response_code(404);
  exit("❌ Payment not found");
}

file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - Found payment record for booking_id $bookingId\n", FILE_APPEND);

// 8. Update payment status
$updateQuery = "UPDATE payments SET status = ?";
$params = [$paymentStatus];
if ($paymentStatus === 'paid') {
  $updateQuery .= ", paid_at = CURRENT_TIMESTAMP";
}
$updateQuery .= " WHERE xendit_invoice_id = ?";
$params[] = $invoiceId;

$stmt = $pdo->prepare($updateQuery);
$stmt->execute($params);

// 9. If paid, update booking and send email
if ($paymentStatus === 'paid' && $bookingData['is_confirmed'] != true) {
  $pdo->prepare("UPDATE bookings SET booking_status = 'confirmed', is_confirmed = TRUE WHERE booking_id = ?")->execute([$bookingId]);
  file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - Booking confirmed\n", FILE_APPEND);
  if ($paymentStatus === 'paid') {
    $extraFields = [
      'payment_method' => $data['payment_method'] ?? null,
    ];

    $stmt = $pdo->prepare("
  UPDATE payments 
  SET status = ?, payment_method = ?
  WHERE xendit_invoice_id = ?
");

    $stmt->execute([
      $paymentStatus,
      $extraFields['payment_method'],
      $invoiceId
    ]);
  }
  try {
    $roomName = $roomData['room_name'] ?? 'Room';
    $roomPrice = (float)($roomData['price'] ?? 0);
    $amountFormatted = number_format($payment['amount'], 2);
    $processingFee = $payment['amount'] - $roomPrice;

    $mail = new PHPMailer(true);
    $mail->SMTPDebug = 3;
    $mail->Debugoutput = function ($str, $level) {
      file_put_contents("mail_debug_log.txt", date("Y-m-d H:i:s") . " - SMTP Debug [$level]: $str\n", FILE_APPEND);
    };

    $mail->isSMTP();
    $mail->Host = 'smtp.sendgrid.net';
    $mail->SMTPAuth = true;
    $mail->Username = 'apikey';
    $mail->Password = $config['sendgrid_api_key'];
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;

    $recipientName = $bookingData['full_name'] ?? 'Guest';
    $recipientEmail = $bookingData['email'] ?? null;

    if (!$recipientEmail || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
      file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - Invalid or missing email\n", FILE_APPEND);
      http_response_code(400);
      exit("❌ Invalid or missing booking email");
    }

    $mail->setFrom($config['email_from'], $config['email_from_name']);
    $mail->addAddress($recipientEmail, $recipientName);
    $mail->isHTML(true);
    $mail->Subject = 'Booking Payment Receipt - ' . $bookingData['booking_code'] ?? 'N/A';

    // Booking details (for breakdown)
    $bookingData['booking_code'] = $booking['booking_code'] ?? 'UNKNOWN';
    $bookingData['check_in_date'] = $booking['check_in_date'];
    $bookingData['check_in_time'] = $booking['check_in_time'];
    $bookingData['check_out_date'] = $booking['check_out_date'] ?? null;
    $bookingData['adults'] = $booking['adults'] ?? 2;
    $bookingData['children'] = $booking['children'] ?? 0;

    $checkInDate = $bookingData['check_in_date'] ?? '—';
    $checkInTime = $bookingData['check_in_time'] ?? '—';
    $checkOutDate = $bookingData['check_out_date'] ?? '—';
    $adults = $bookingData['adults'] ?? 1;
    $children = $bookingData['children'] ?? 0;
    $invoiceId = $payment['xendit_invoice_id'] ?? null;
    $invoiceUrl = $invoiceId ? "https://checkout-staging.xendit.co/web/{$invoiceId}" : null;


    $mail->Body = "
      <div style='font-family: Poppins, sans-serif; max-width: 600px; margin: auto; border:1px solid #ddd; padding: 24px; color: #1f2937; background-color: #ffffff;'>
        <style>
          @media only screen and (max-width: 600px) {
            .email-container {
              padding: 16px !important;
            }
            .email-table td {
              display: block;
              width: 100%;
            }
          }
        </style>

        <div class='email-container'>
          <h2 style='color: #065f46;'>Payment Confirmation</h2>
          <p style='font-size: 16px;'>Hello <strong>{$bookingData['full_name']}</strong>,</p>
          <p style='font-size: 15px;'>Thank you for your payment. Your booking receipt is attached, and your details are listed below.</p>

          <table class='email-table' style='width: 100%; font-size: 14px; margin-top: 20px; border-collapse: collapse;'>
            <tr><td style='padding: 8px 0;'><strong>Room</strong></td><td>{$roomName}</td></tr>
            <tr><td style='padding: 8px 0;'><strong>Check-in</strong></td><td>{$checkInDate} @ {$checkInTime}</td></tr>
            <tr><td style='padding: 8px 0;'><strong>Check-out</strong></td><td>{$checkOutDate}</td></tr>
            <tr><td style='padding: 8px 0;'><strong>Guests</strong></td><td>{$adults} Adult(s), {$children} Child(ren)</td></tr>
            <tr><td style='padding: 8px 0;'><strong>Booking Code</strong></td><td>{$bookingData['booking_code']}</td></tr>
            <tr><td style='padding: 8px 0;'><strong>Amount Paid</strong></td><td>₱{$amountFormatted}</td></tr>
            <tr>
              <td style='padding: 8px 0;'><strong>Invoice Link</strong></td>
              <td>" .
      ($invoiceUrl
        ? "<a href='{$invoiceUrl}' style='color: #2563eb; text-decoration: underline;'>View Invoice</a>"
        : "<em>Not available</em>") .
      "</td>
            </tr>

            <tr><td style='padding: 8px 0;'><strong>Payment Method</strong></td><td>Xendit (" . ucfirst($data['payment_method'] ?? 'Unknown') . ")</td></tr>
          </table>

          <div style='margin-top: 24px; text-align: center;'>
            <a href='https://villarosal.free.nf/php/confirm.php?token={$bookingData['confirmation_token']}' style='display: inline-block; background: #10b981; color: white; padding: 14px 28px; border-radius: 6px; text-decoration: none; font-weight: bold;'>Confirm Booking</a>
          </div>

          <p style='margin-top: 24px;'>We look forward to hosting you at <strong>Villa Rosal Beach Resort</strong>.</p>

          <footer style='font-size: 12px; color: #6b7280; margin-top: 30px; border-top: 1px solid #e5e7eb; padding-top: 12px;'>
            Need help? Email us at <a href='mailto:support@yourhotel.com' style='color: #2563eb;'>support@yourhotel.com</a>
          </footer>
        </div>
      </div>";

    // Setup Dompdf
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);

    // Prepare invoice variables
    // === Replace hardcoded with actual values from database
    $businessName = "Villa Rosal Beach Resort";
    $businessAddress = "Purok 4, Brgy. Catagman, Samal, Philippines";
    $businessEmail = "fo.villarosal2025@gmail.com";
    $businessPhone = "0985 895 1990";

    $invoiceNumber = $payment['xendit_invoice_id'] ?? '—';
    $invoiceDate = date("F j, Y");
    $dueDate = date("F j, Y", strtotime("+1 day"));

    $customerName = $booking['full_name'] ?? 'Guest';
    $customerEmail = $booking['email'] ?? '—';
    $customerPhone = $booking['contact_number'] ?? '—'; // 🔁 Was 'phone_number'

    $logoUrl = "https://villarosal.free.nf/img/logo.png";

    $checkIn = date("F j, Y", strtotime($booking['check_in_date'])) . " " . date("g:i A", strtotime($booking['check_in_time']));
    $checkOut = date("F j, Y", strtotime($booking['check_out_date'] ?? '+1 day'));
    $totalAmount = $payment['amount'] ?? 0;

    // Re-fetch latest payment FIRST
    $paymentUrl = $apiUrl . "/rest/v1/payments?booking_id=eq.{$bookingId}&select=*";
    $headers = [
      'apikey: ' . $apiKey,
      'Authorization: Bearer ' . $apiKey
    ];
    $ch = curl_init($paymentUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);

    $paymentList = json_decode($response, true);
    $payment = $paymentList[0] ?? null;

    if (!$payment) {
      file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - Payment not found for booking_id $bookingId\n", FILE_APPEND);
      http_response_code(404);
      exit("Payment not found.");
    }

    // ✅ Then use the fresh $payment below
    $createdUtc = new DateTime($payment['created'], new DateTimeZone('UTC'));
    $createdUtc->setTimezone(new DateTimeZone('Asia/Manila'));
    $createdPH = $createdUtc->format('F j, Y \a\t g:i A');

    $paymentData = [
      'base_price' => $room['price'] ?? 0,
      'fee' => $payment['amount'] - ($room['price'] * $qty),
      'amount' => $payment['amount'],
      'xendit_invoice_id' => $payment['xendit_invoice_id'],
      'created_at' => $createdPH,
      'payment_method' => $payment['payment_channel'] ?? 'xendit',
      'qty' => $qty,
      'status' => $payment['status'] ?? 'pending',
    ];

    // Generate QR code data
    $qrData = json_encode([
      'booking_code' => $bookingData['booking_code'],
      'invoice_id' => $paymentData['xendit_invoice_id']
    ]);
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?data=' . urlencode($qrData) . '&size=200x200';

    ob_start();
    include 'invoices/invoice-template.php';
    $html = ob_get_clean();

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $bookingCode = $bookingData['booking_code'] ?? 'UNKNOWN';

    if ($bookingCode === 'UNKNOWN') {
      file_put_contents("webhook_debug.txt", "booking_code is missing for booking_id $bookingId\n", FILE_APPEND);
      http_response_code(400);
      exit("Missing booking_code");
    }

    // Save or stream PDF
    $pdfOutput = $dompdf->output();
    if (!$pdfOutput) {
      file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - PDF rendering failed\n", FILE_APPEND);
      http_response_code(500);
      exit("Failed to generate PDF");
    }

    $pdfPath = __DIR__ . "/invoices/booking_invoice_{$bookingCode}.pdf";
    file_put_contents($pdfPath, $pdfOutput);

    // Send via email
    $mail->addAttachment($pdfPath);
    $mail->send();

    file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - Email sent to {$bookingData['email']}\n", FILE_APPEND);
  } catch (Exception $e) {
    file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - Mail error: {$e->getMessage()}\n", FILE_APPEND);
  }
} elseif (in_array($paymentStatus, ['expired', 'failed'])) {
  $stmt = $pdo->prepare("UPDATE bookings SET booking_status = 'expired' WHERE booking_id = ?");
  $stmt->execute([$bookingId]);
  file_put_contents("webhook_debug.txt", date("Y-m-d H:i:s") . " - Booking marked as expired\n", FILE_APPEND);
}

// ✅ Final response
http_response_code(200);
echo "✅ Payment status updated to '$paymentStatus' for booking ID $bookingId";
