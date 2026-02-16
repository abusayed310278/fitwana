<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Stripe\StripeClient;

class ShopController extends BaseApiController
{
    /**
     * Get all products.
     */
    public function products(Request $request): JsonResponse
    {
        $query = Product::with('productSpecifications', 'reviews.user')->orderByDESC('id');

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        $products = $query->where('stock_quantity', '>', 0)
            ->paginate(150);

        return $this->paginatedSuccess($products, 'Products retrieved successfully');
    }

    /**
     * Get product details.
     */
    public function productDetails(Product $product): JsonResponse
    {
        $product->load('productSpecifications');
        return $this->success($product, 'Product details retrieved');
    }

    /**
     * Get all categories.
     */
    public function categories(): JsonResponse
    {
        $categories = ProductCategory::all();
        return $this->success($categories, 'Categories retrieved successfully');
    }

    /**
     * Get products by category.
     */
    public function productsByCategory(ProductCategory $category): JsonResponse
    {
        $products = $category->products()
            ->with('productSpecifications')
            ->where('stock_quantity', '>', 0)
            ->paginate(12);

        return $this->paginatedSuccess($products, 'Products retrieved successfully');
    }

    /**
     * Add item to cart.
     */
    public function addToCart(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $user = $request->user();
        $product = Product::findOrFail($request->product_id);

        if ($product->stock_quantity < $request->quantity) {
            return $this->error('Insufficient stock available');
        }

        $cartItem = CartItem::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($cartItem) {
            // already in cart → update quantity
            $cartItem->quantity += $request->quantity;
            $cartItem->save();
        } else {
            // new entry
            $cartItem = CartItem::create([
                'user_id'    => $user->id,
                'product_id' => $product->id,
                'quantity'   => $request->quantity,
                'price'      => $product->price, // snapshot price
            ]);
        }

        return $this->success([
            'cart_item' => $cartItem->load('product'),
            'cart_count' => CartItem::where('user_id', $user->id)->count(),
        ], 'Item added to cart successfully');
    }

    /**
     * Get cart contents.
     */
    public function cart(Request $request): JsonResponse
    {
        $user = $request->user();

        $cartItems = $user->cartItems()->with('product')->get();

        $total = $cartItems->sum(fn($item) => $item->price * $item->quantity);

        return $this->success([
            'items' => $cartItems->map(function ($item) {
                return [
                    'cart_id'  => $item->id,
                    'product'  => $item->product,
                    'quantity' => $item->quantity,
                    'price'    => $item->price,
                    'subtotal' => $item->price * $item->quantity,
                ];
            }),
            'total' => $total,
            'count' => $cartItems->count(),
        ], 'Cart retrieved successfully');
    }

    /**
     * Update cart item quantity.
     */
    public function updateCartItem(Request $request, CartItem $item): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        // Ensure item belongs to current user
        if ($item->user_id != $request->user()->id) {
            return $this->error('Unauthorized action', 403);
        }

        // Check stock availability
        if ($item->product->stock_quantity < $request->quantity) {
            return $this->error('Insufficient stock available');
        }

        $item->quantity = $request->quantity;
        $item->save();

        return $this->success([
            'cart_item' => $item->load('product'),
            'subtotal'  => $item->price * $item->quantity,
        ], 'Cart item updated successfully');
    }

    /**
     * Remove item from cart.
     */
    public function removeFromCart(Request $request, CartItem $item): JsonResponse
    {
        // Ensure item belongs to current user
        if ($item->user_id != $request->user()->id) {
            return $this->error('Unauthorized action', 403);
        }

        $item->delete();

        return $this->success([], 'Item removed from cart successfully');
    }



    public function checkout(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shipping_address' => 'required|array',
            'shipping_address.street' => 'required|string',
            'shipping_address.city' => 'required|string',
            'shipping_address.postal_code' => 'required|string',
            'shipping_address.country' => 'required|string',
            'stripe_token' => 'required|string', // frontend se aayega
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $user = $request->user();

        // Get cart items
        $cartItems = CartItem::with('product')->where('user_id', $user->id)->get();
        if ($cartItems->isEmpty()) {
            return $this->error('Cart is empty');
        }

        $total = 0;
        foreach ($cartItems as $item) {
            $total += $item->price * $item->quantity;
        }

        // ✅ Stripe charge/payment
        $stripe = new StripeClient(config('services.stripe.secret'));

        try {
            // Customer create/update
            $customer = $stripe->customers->create([
                'email' => $user->email,
                'name'  => $user->name,
                'source'=> $request->stripe_token, // token use ho raha hai
            ]);

            // Payment Intent ya direct Charge (token ke liye charge use hota hai)
            $charge = $stripe->charges->create([
                'customer'    => $customer->id,
                'amount'      => $total * 100, // cents
                'currency'    => 'usd',
                'description' => 'Order Payment - ' . $user->id,
            ]);

            if ($charge->status !== 'succeeded') {
                return $this->error('Payment failed. Please try again.');
            }

            // ✅ Create order
            $order = Order::create([
                'user_id'         => $user->id,
                'order_number'    => 'ORD-' . time(),
                'total_amount'    => $total,
                // 'status'          => 'confirmed',
                'shipping_address'=> $request->shipping_address,
                'payment_id'      => $charge->id, // Stripe charge ID
                'payment_method'  => 'stripe',
                'order_date'      => now(),
            ]);

            // Order items
            foreach ($cartItems as $cartItem) {
                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $cartItem->product_id,
                    'quantity'   => $cartItem->quantity,
                    'price'      => $cartItem->price,
                ]);

                // Update stock
                $cartItem->product->decrement('stock_quantity', $cartItem->quantity);
            }

            // Clear cart
            CartItem::where('user_id', $user->id)->delete();

            return $this->success(
                $order->load('items.product'),
                'Order placed and payment successful'
            );

        } catch (\Exception $e) {
            return $this->serverError('Payment error: ' . $e->getMessage());
        }
    }


    /**
     * Get user orders.
     */
    public function orders(Request $request): JsonResponse
    {
        $orders = $request->user()->orders()
            ->with('items.product')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return $this->paginatedSuccess($orders, 'Orders retrieved successfully');
    }

    /**
     * Get order details.
     */
    public function orderDetails(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id != $request->user()->id) {
            return $this->forbidden('You can only view your own orders');
        }

        return $this->success($order->load('items.product'), 'Order details retrieved');
    }
}
