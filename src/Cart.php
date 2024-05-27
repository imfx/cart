<?php

namespace Cart;

use Closure;
use Cart\Fee;
use Cart\Contracts\Buyable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Session\SessionManager;
use Illuminate\Database\DatabaseManager;
use Cart\Exceptions\InvalidRowIDException;
use Cart\Exceptions\UnknownModelException;
use Illuminate\Contracts\Events\Dispatcher;
use Cart\Exceptions\CartAlreadyStoredException;

class Cart
{
    /**
     * Instance of the session manager.
     *
     * @var \Illuminate\Session\SessionManager
     */
    protected $session;

    /**
     * Instance of the event dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    private $events;

    /**
     * The key to store the current instance in the session.
     *
     * @var string
     */
    private $currentInstanceKey = '_instance';

    /**
     * Cart constructor.
     *
     * @param \Illuminate\Session\SessionManager      $session
     * @param \Illuminate\Contracts\Events\Dispatcher $events
     */
    public function __construct(SessionManager $session, Dispatcher $events)
    {
        $this->session = $session;
        $this->events = $events;
    }

    /**
     * Set the current cart instance.
     *
     * @param string|null $instance
     * @return \Cart\Cart
     */
    public function setInstance($instance)
    {
        $this->session->put(
            $this->getSessionKey($this->currentInstanceKey),
            $instance
        );

        return $this;
    }

    /**
     * Get the current cart instance without the cart identifier.
     *
     * @return string
     */
    public function instance()
    {
        return str_replace(config('cart.identifier', 'cart') . '.', '', $this->currentInstance());
    }

    /**
     * Get the current cart instance with the cart identifier.
     *
     * @return string=
     */
    private function currentInstance()
    {
        $instance = $this->session->get(
            $this->getSessionKey($this->currentInstanceKey)
        ) ?? 'default';

        return $this->getSessionKey($instance);
    }

    /**
     * Add an item to the cart.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $quantity
     * @param float     $price
     * @param array     $options
     * @param float     $taxrate
     * @return \Cart\CartItem
     */
    public function add($id, $name = null, $quantity = null, $price = null, array $options = [], $taxrate = null)
    {
        if ($this->isMulti($id)) {
            return array_map(function ($item) {
                return $this->add($item);
            }, $id);
        }

        if ($id instanceof CartItem) {
            $cartItem = $id;
        } else {
            $cartItem = $this->createCartItem($id, $name, $quantity, $price, $options, $taxrate);
        }

        $content = $this->getContent();

        if ($content->has($cartItem->rowId)) {
            $cartItem->quantity += $content->get($cartItem->rowId)->quantity;
        }

        $content->put($cartItem->rowId, $cartItem);

        $this->events->dispatch('cart.added', $cartItem);

        $this->session->put($this->currentInstance(), $content);

        return $cartItem;
    }

    /**
     * Update the cart item with the given rowId.
     *
     * @param string $rowId
     * @param mixed  $quantity
     * @return \Cart\CartItem
     */
    public function update($rowId, $quantity, array $options = [])
    {
        $cartItem = $this->get($rowId);

        if ($quantity instanceof Buyable) {
            $cartItem->updateFromBuyable($quantity, $options);
        } elseif (is_array($quantity)) {
            $cartItem->updateFromArray($quantity);
        } else {
            $cartItem->quantity = $quantity;
        }

        $content = $this->getContent();

        if ($rowId !== $cartItem->rowId) {
            $content->pull($rowId);

            if ($content->has($cartItem->rowId)) {
                $existingCartItem = $this->get($cartItem->rowId);
                $cartItem->setQuantity($existingCartItem->quantity + $cartItem->quantity);
            }
        }

        if ($cartItem->quantity <= 0) {
            $this->remove($cartItem->rowId);
            return;
        } else {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->events->dispatch('cart.updated', $cartItem);
        $this->session->put($this->currentInstance(), $content);

        return $cartItem;
    }

    /**
     * Remove the cart item with the given rowId from the cart.
     *
     * @param string $rowId
     * @return void
     */
    public function remove($rowId)
    {
        $cartItem = $this->get($rowId);

        $content = $this->getContent();
        $content->pull($cartItem->rowId);

        $this->events->dispatch('cart.removed', $cartItem);
        $this->session->put($this->currentInstance(), $content);
    }

    /**
     * Check if an item exists.
     *
     * @param string $rowId
     * @return bool
     */
    public function exists($rowId)
    {
        return $this->getContent()->has($rowId);
    }

    /**
     * Get a cart item from the cart by its rowId.
     *
     * @param string $rowId
     * @return \Cart\CartItem
     */
    public function get($rowId)
    {
        $content = $this->getContent();

        if ( ! $content->has($rowId))
            throw new InvalidRowIDException("The cart does not contain rowId {$rowId}.");

        return $content->get($rowId);
    }

    /**
     * Destroy the current cart instance.
     *
     * @return void
     */
    public function destroy($preserve = [])
    {
        $metaData = $this->getSessionMetaData();

        $this->session->remove(config('cart.identifier', 'cart'));

        collect($preserve)->each(function ($key) use ($metaData) {
            if (isset($metaData[$key])) {
                $this->setMetaData($key, $metaData[$key]);
            }
        });
    }

    /**
     * Get the content of the cart.
     *
     * @return \Illuminate\Support\Collection
     */
    public function content()
    {
        if (is_null($this->session->get($this->currentInstance()))) {
            return new Collection([]);
        }

        return $this->session->get($this->currentInstance());
    }

    /**
     * Get the number of items in the cart.
     *
     * @return int|float
     */
    public function count()
    {
        $content = $this->getContent();

        return $content->sum('quantity');
    }

    /**
     * Returns true if the cart is empty.
     *
     * @return bool
     */
    public function isEmpty()
    {
        return $this->count() == 0;
    }

    /**
     * Get the total price of the items in the cart.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return string
     */
    public function total()
    {
        $content = $this->getContent();

        $total = $content->reduce(function ($total, CartItem $cartItem) {
            return $total + ($cartItem->quantity * $cartItem->priceTax);
        }, 0);

        $total += $this->totalFee();

        return $total;
    }

    /**
     * Get the total tax of the items in the cart.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function tax()
    {
        $content = $this->getContent();

        $tax = $content->reduce(function ($tax, CartItem $cartItem) {
            return $tax + ($cartItem->quantity * $cartItem->tax);
        }, 0);

        return $tax;
    }

    /**
     * Get the subTotal (total - tax) of the items in the cart.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     * @return float
     */
    public function subTotal()
    {
        $content = $this->getContent();

        $subTotal = $content->reduce(function ($subTotal, CartItem $cartItem) {
            return $subTotal + ($cartItem->quantity * $cartItem->price);
        }, 0);

        return $subTotal;
    }

    public function totalFee()
    {
        $fees = $this->getFees();
        $subTotal = $this->subTotal();

        $totalFee = $fees->reduce(function ($totalFee, Fee $fee) use ($subTotal) {

            if ($fee->isPercentage()) {
                return $totalFee + (($subTotal * $fee->value()) / 100);
            }

            return $totalFee + $fee->value();
        }, 0);

        return $totalFee;
    }

    /**
     * Search the cart content for a cart item matching the given search closure.
     *
     * @param \Closure $search
     * @return \Illuminate\Support\Collection
     */
    public function search(Closure $search)
    {
        $content = $this->getContent();

        return $content->filter($search);
    }

    /**
     * Associate the cart item with the given rowId with the given model.
     *
     * @param string $rowId
     * @param mixed  $model
     * @return void
     */
    public function associate($rowId, $model)
    {
        if(is_string($model) && ! class_exists($model)) {
            throw new UnknownModelException("The supplied model {$model} does not exist.");
        }

        $cartItem = $this->get($rowId);
        $cartItem->associate($model);

        $content = $this->getContent();
        $content->put($cartItem->rowId, $cartItem);

        $this->session->put($this->currentInstance(), $content);
    }

    /**
     * Set the tax rate for the cart item with the given rowId.
     *
     * @param string    $rowId
     * @param int|float $taxRate
     * @return void
     */
    public function setTax($rowId, $taxRate)
    {
        $cartItem = $this->get($rowId);
        $cartItem->setTaxRate($taxRate);

        $content = $this->getContent();
        $content->put($cartItem->rowId, $cartItem);

        $this->session->put($this->currentInstance(), $content);
    }

    /**
     * Store an the current instance of the cart.
     *
     * @param mixed $identifier
     * @return void
     */
    public function store($identifier)
    {
        $content = $this->getContent();

        $this->getConnection()
             ->table($this->getTableName())
             ->where('identifier', $identifier)
             ->where('instance', $this->instance())
             ->delete();

        $this->getConnection()->table($this->getTableName())->insert([
            'identifier' => $identifier,
            'instance' => $this->instance(),
            'content' => serialize($content),
            'created_at'=> new \DateTime()
        ]);

        $this->events->dispatch('cart.stored');
    }

    /**
     * Restore the cart with the given identifier.
     *
     * @param mixed $identifier
     * @return void
     */
    public function restore($identifier)
    {
        if( ! $this->storedCartWithIdentifierExists($identifier)) {
            return;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where('instance', $this->instance())
            ->where('identifier', $identifier)->first();

        $storedContent = unserialize($stored->content);

        $currentInstance = $this->instance();

        $this->setInstance($stored->instance);

        $content = $this->getContent();

        foreach ($storedContent as $cartItem) {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->events->dispatch('cart.restored');

        $this->session->put($this->currentInstance(), $content);

        $this->setInstance($currentInstance);

    }

    /**
     * Deletes the stored cart with given identifier
     *
     * @param mixed $identifier
     */
    protected function deleteStoredCart($identifier) {
        $this->getConnection()
             ->table($this->getTableName())
             ->where('identifier', $identifier)
             ->delete();
    }

    /**
     * Magic method to make accessing the total, tax and subTotal properties possible.
     *
     * @param string $attribute
     * @return float|null
     */
    public function __get($attribute)
    {
        if ($attribute === 'total') {
            return $this->total();
        }

        if ($attribute === 'tax') {
            return $this->tax();
        }

        if ($attribute === 'subTotal') {
            return $this->subTotal();
        }

        if ($attribute === 'totalFee') {
            return $this->totalFee();
        }

        return null;
    }

    /**
     * Get the carts content, if there is no cart content set yet, return a new empty Collection
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getContent()
    {
        $content = $this->session->has($this->currentInstance())
            ? $this->session->get($this->currentInstance())
            : new Collection;

        return $content;
    }

    /**
     * Create a new CartItem from the supplied attributes.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $quantity
     * @param float     $price
     * @param array     $options
     * @param float     $taxrate
     * @return \Cart\CartItem
     */
    private function createCartItem($id, $name, $quantity, $price, array $options, $taxrate)
    {
        if ($id instanceof Buyable) {
            $cartItem = CartItem::fromBuyable($id, $quantity ?: []);
            $cartItem->setQuantity($name ?: 1);
            $cartItem->associate($id);
        } elseif (is_array($id)) {
            $cartItem = CartItem::fromArray($id);
            $cartItem->setQuantity($id['quantity']);
        } else {
            $cartItem = CartItem::fromAttributes($id, $name, $price, $options);
            $cartItem->setQuantity($quantity);
        }

        if ($taxrate) {
            $cartItem->setTaxRate($taxrate);
        } else {
            $cartItem->setTaxRate(config('cart.tax'));
        }

        return $cartItem;
    }

    /**
     * Check if the item is a multidimensional array or an array of Buyables.
     *
     * @param mixed $item
     * @return bool
     */
    private function isMulti($item)
    {
        if ( ! is_array($item)) return false;

        return is_array(head($item)) || head($item) instanceof Buyable;
    }

    /**
     * @param $identifier
     * @return bool
     */
    protected function storedCartWithIdentifierExists($identifier)
    {
        return $this->getConnection()
                    ->table($this->getTableName())
                    ->where('identifier', $identifier)
                    ->where('instance', $this->instance())
                    ->exists();
    }

    /**
     * Get the database connection.
     *
     * @return \Illuminate\Database\Connection
     */
    protected function getConnection()
    {
        $connectionName = $this->getConnectionName();

        return app(DatabaseManager::class)->connection($connectionName);
    }

    /**
     * Get the database table name.
     *
     * @return string
     */
    protected function getTableName()
    {
        return config('cart.database.table', 'cart');
    }

    /**
     * Get the database connection name.
     *
     * @return string
     */
    private function getConnectionName()
    {
        $connection = config('cart.database.connection');

        return is_null($connection) ? config('database.default') : $connection;
    }

    private function getMetaDataSessionKey()
    {
        return $this->getSessionKey('_metadata');
    }

    private function getSessionMetaData()
    {
        $key = $this->getMetaDataSessionKey();

        return $this->session->get($key) ?? [];
    }

    private function setSessionMetaData($data)
    {
        $key = $this->getMetaDataSessionKey();

        return $this->session->put($key, $data);
    }

    /**
     * Get the metadata for the cart.
     *
     * @param string|null $key
     * @return mixed
     * @deprecated Use metadata() instead
     */
    public function getMetaData($key = null)
    {
        return $key ? $this->metadataFor(key: $key) : $this->metadata();
    }

    /**
     * Get the metadata for the cart.
     *
     * @return array
     */
    public function metadata(): array
    {
        return $this->getSessionMetaData();
    }

    /**
     * Get the metadata for the given key.
     *
     * @return mixed
     */
    public function metadataFor(string $key)
    {
        return Arr::get($this->metadata(), $key);
    }

    public function setMetaData($key, $data)
    {
        $metaData = $this->getSessionMetaData();

        $newMetaData = data_set($metaData, $key, $data);

        return $this->setSessionMetaData(array_merge($metaData, $newMetaData));
    }

    public function removeMetaData($key = null)
    {
        if (is_null($key)) {
            $key = $this->getMetaDataSessionKey();

            return $this->session->remove($key);
        }

        $metaData = $this->getSessionMetaData();

        Arr::forget($metaData, $key);

        return $this->setSessionMetaData($metaData);
    }

    private function getSessionKey($key)
    {
        return sprintf('%s.%s', config('cart.identifier', 'cart'), $key);
    }

    private function getFeesSessionKey()
    {
        return $this->getSessionKey('_fees');
    }

    public function getFees()
    {
        $key = $this->getFeesSessionKey();

        return $this->session->get($key) ?? new Collection;
    }

    public function addFee($identifier, $title, $value)
    {
        $key = $this->getFeesSessionKey();

        $fee = new Fee($identifier, $title, $value);

        $fees = $this->getFees();

        $fees->put($identifier, $fee);

        return $this->session->put($key, $fees);
    }

    /**
     * Remove the fee item with the given identifier.
     *
     * @param string $identifier
     * @return void
     */
    public function removeFee($identifier = null)
    {
        $key = $this->getFeesSessionKey();

        if (is_null($identifier)) {
            return $this->session->remove($key);
        }

        $fees = $this->getFees();
        $fees->pull($identifier);

        return $this->session->put($key, $fees);
    }
}
