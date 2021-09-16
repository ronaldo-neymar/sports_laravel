<?php

namespace App\Http\Controllers\Clients;

// Gọi các table trong model
use App\Models\Bill;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Category;

// use Exception;
use Mockery\Exception;
// Các lớp để thực hiện thanh toán paypal
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Transaction;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Payment;
use PayPal\Api\Payer;
use PayPal\Rest\ApiContext;
use PayPal\Auth\OAuthTokenCredential;

use App\Models\BillDetail;
use DateTime;
use App\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Session;
use PayPal\Api\PaymentExecution;

class PaymentController extends Controller
{

    private $apiContext;
    public function __construct()
    {

        // Khởi tạo ngữ cảnh
        $this->apiContext = new ApiContext(
            new OAuthTokenCredential(
                config('paypal.client_id'),
                config('paypal.secret')
            )
        );
        $this->apiContext->setConfig(config('paypal.settings'));

        $this->totalAmount = 0;
    }

    public function checkout(Request $request)
    {
        $cart = Session::get('cart');
        $category = Category::where('parent_id', '=', null)->get();
        return view('clients.orders.checkout', compact('category', 'cart'));
    }

    // Tạo hàm postCheckout để thực hiện thanh toán và đặt hàng truyền tham số Request vào
    public function postCheckout(Request $request)
    {

        // Gọi giỏ hàng có trong checkout ra
        $cart = Session::get('cart');
        // Tạo biến để lưu data vào Customer(khách hàng)
        $customer = new Customer();
        $data = $request->all();
        $customer->fill($data);
        $customer->save();

        // Tạo biến để lưu data vào Bill(Hóa đơn)
        $bill = new Bill();
        $bill->cutomer_id = $customer->id;
        $bill->total = $cart->totalPrice;
        $bill->date_order = date('y-m-d');
        $bill->payments = $request->payments;
        $bill->bill_active = $bill->payments;
        $bill->note = $request->note;
        $bill->save();

        // Tạo biến trỏ đến thông tin người đặt hàng
        $email = $customer->email;
        $name = $customer->full_name;

        // Thực hiện lưu các sản phẩm vào BillDetail(Chi tiết hóa đơn, đơn hàng)
        foreach ($cart->products as $key => $value) {
            $billdetail = new BillDetail();
            $billdetail->bill_id = $bill->id;
            $billdetail->product_id = $key;
            $billdetail->quantity = $value['quantity'];
            $billdetail->unit_price = $value['price'];
            $billdetail->save();
        }
        if ($bill->payments == "0") {
            Mail::send('clients.email.order', [
                'customer' => $customer,
                'name' => $name,
                'order' => $bill,
                'items' => $cart->products,
            ], function ($mail) use ($email, $name) {
                $mail->from('thientamjvb@gmail.com');
                $mail->to($email, $name);
                $mail->subject('Gửi email đặt hàng');
            });
            Session::forget('cart');
            return redirect()->route('alert');
        } else {

            $totalPrice = $cart->totalPriceUsd;
            $payer = new Payer();
            $payer->setPaymentMethod("paypal");
            // ### Itemized information
            // (Optional) Lets you specify item wise
            // information

            // dd(round( $PriceAll ));die;
            foreach ($cart->products as $key => $value) {
                // dd($value);die;

                $item = new Item();
                $item->setName($value['productInfo']->title)
                    ->setCurrency("USD")
                    ->setQuantity($value['quantity'])
                    ->setSku($value['productInfo']->id) // Similar to `item_number` in Classic API
                    ->setPrice(round($value['price'] / 23000, 2));
                $this->itemList[] = $item;
                // $this->totalAmount += $value['price'] * $value['quantity'];
                //    dd( $this->totalAmount);die;
                //   $this->totalAmount =  round($this->totalAmount) ;
            }
            // dd($totalPrice);die;
            $itemList = new ItemList();
            $itemList->setItems($this->itemList);
            //    dd($itemList);die;
            // dd(  $itemList);die;
            // ### Additional payment details
            // Use this optional field to set additional
            // payment information such as tax, shipping
            // charges etc.

            $details = new Details();
            $details->setSubtotal($totalPrice);

            // dd($details);die;
            // ### Amount
            // Lets you specify a payment amount.
            // You can also specify additional details
            // such as shipping, tax.
            $amount = new Amount();
            $amount->setCurrency("USD")
                ->setTotal($totalPrice)
                ->setDetails($details);

            // dd($details);die;
            // ### Transaction
            // A transaction defines the contract of a
            // payment - what is the payment for and who
            // is fulfilling it. 
            $transaction = new Transaction();
            $transaction->setAmount($amount)
                ->setItemList($itemList)
                ->setDescription("Payment description")
                ->setInvoiceNumber(uniqid());

            // ### Redirect urls
            // Set the urls that the buyer must be redirected to after 
            // payment approval/ cancellation.
            // $baseUrl = getBaseUrl();
            $redirectUrls = new RedirectUrls();
            $redirectUrls->setReturnUrl(route('payment.create'))
                ->setCancelUrl(route('payment.create'));

            // ### Payment
            // A Payment Resource; create one using
            // the above types and intent set to 'sale'
            $payment = new Payment();
            $payment->setIntent("sale")
                ->setPayer($payer)
                ->setRedirectUrls($redirectUrls)
                ->setTransactions(array($transaction));

            // For Sample Purposes Only.
            // $request = clone $payment;

            // ### Create Payment
            // Create a payment by calling the 'create' method
            // passing it a valid apiContext.
            // (See bootstrap.php for more on `ApiContext`)
            // The return object contains the state and the
            // url to which the buyer must be redirected to
            // for payment approval
            try {
                $payment->create($this->apiContext);

                // $token = $this->getPayPalTokenFromUrl($redirectUrls->getApprovalLink());
            } catch (Exception $ex) {
                // NOTE: PLEASE DO NOT USE RESULTPRINTER CLASS IN YOUR ORIGINAL CODE. FOR SAMPLE ONLY
                // ResultPrinter::printError("Created Payment Using PayPal. Please visit the URL to Approve.", "Payment", null, $request, $ex);
                // echo "Faild";
                exit(1);
            }
            // ### Get redirect url
            // The API response provides the url that you must redirect
            // the buyer to. Retrieve the url from the $payment->getApprovalLink()
            // method
            $approvalUrl = $payment->getApprovalLink();
            // NOTE: PLEASE DO NOT USE RESULTPRINTER CLASS IN YOUR ORIGINAL CODE. FOR SAMPLE ONLY
            // ResultPrinter::printResult("Created Payment Using PayPal. Please visit the URL to Approve.", "Payment", "<a href='$approvalUrl' >$approvalUrl</a>", $request, $payment);
            // echo "<pre>";

            Session::put('payment_id', $payment->id);
            Session::forget('cart');
            return redirect()->to($approvalUrl);
        }
    }

    public function alertMessa(Request $request)
    {

        $category = Category::where('parent_id', '=', null)->get();
        return view('clients.alert.message', compact('category'));
    }

    public function listOrder(Request $request)
    {
        $category = Category::where('parent_id', '=', null)->get();
        $bills = Bill::orderBy('id', 'desc')->Paginate(4);
        // dd($billdetail);die;
        return view('clients.orders.list', compact('category', 'bills'));
    }

    public function create(Request $request)
    {
        $payment_id = Session::get('payment_id');
        Session::forget('payment_id');

        $execution = new PaymentExecution();
        $execution->setPayerId($request->input('PayerID'));

        $payment = Payment::get($payment_id, $this->apiContext);

        try {
            $result = $payment->execute($execution, $this->apiContext);
            if ($result->getState() == "approved") {

                return redirect()->route('list-order');
            }
        } catch (Exception $e) {
            return "faild";
        }
    }

    public function detailOrder(Request $request)
    {
        $bill = Bill::find($request->id);
        $billdetail = $bill->hasBillDetail;
        // dd($billdetail);die;
        $customerId = $bill->hasCustomer->id;
        $customer = Customer::find($customerId);
        $category = Category::where('parent_id', '=', null)->get();
        return view('clients.orders.detail', compact('category', 'billdetail', 'customer', 'bill'));
    }

    public function billDestroy(Request $request)
    {
        $data = $request->all();
        $bill = Bill::find($request->id);
        $bill->bill_destroy = $data['cause'];
        $bill->bill_active = 6;
        $bill->save();
    }
}