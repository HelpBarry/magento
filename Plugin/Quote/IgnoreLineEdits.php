<?php

namespace Bluebarry\Bluebarry\Plugin\Quote;

use Bluebarry\Bluebarry\Observer\NoteAddToCart;
use Magento\Quote\Model\Quote;

/**
 * Editing a line in the cart (its Edit, then Update Cart) re-adds it through Quote::updateItem, which
 * dispatches the add event with the line's new quantity. That is no add to the cart.
 */
class IgnoreLineEdits
{
    /**
     * @var NoteAddToCart
     */
    private $notes;

    /**
     * @param NoteAddToCart $notes
     */
    public function __construct(NoteAddToCart $notes)
    {
        $this->notes = $notes;
    }

    /**
     * @param Quote $subject
     * @param callable $proceed
     * @param mixed ...$args
     * @return mixed
     */
    public function aroundUpdateItem(Quote $subject, callable $proceed, ...$args)
    {
        return $this->notes->ignoring(function () use ($proceed, $args) {
            return $proceed(...$args);
        });
    }
}
