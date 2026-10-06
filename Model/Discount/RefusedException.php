<?php

namespace Bluebarry\Bluebarry\Model\Discount;

/**
 * A discount code that cannot be made as described: nothing was made, and asking again gives the same.
 */
class RefusedException extends \Exception
{
}
