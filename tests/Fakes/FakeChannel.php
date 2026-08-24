<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Tests\Fakes;

use Liberu\Ecommerce\SocialCommerce\Contracts\ShopChannel;
use Liberu\Ecommerce\SocialCommerce\Data\ChannelReceipt;
use Liberu\Ecommerce\SocialCommerce\Data\RemoteItem;
use RuntimeException;

/** A steerable adapter. Nothing here talks to a network, and nothing in src/ does either. */
final class FakeChannel implements ShopChannel
{
    public bool $accept = true;

    public bool $throwOnWrite = false;

    public bool $throwOnRead = false;

    /** @var array<string, string> */
    public array $remoteRefs = [];

    /** @var array<string, array<int, string>> */
    public array $errors = [];

    public ?string $message = null;

    /** @var array<string, RemoteItem>|null */
    public ?array $statuses = [];

    /** @var array<int, array{shopRef: string, secret: string, itemRefs: array<int, string>}> */
    public array $publishes = [];

    /** @var array<int, array{shopRef: string, secret: string, itemRefs: array<int, string>}> */
    public array $withdrawals = [];

    /** @var array<int, array<int, string>> */
    public array $reads = [];

    public function publish(string $shopRef, string $secret, array $items): ChannelReceipt
    {
        $this->publishes[] = ['shopRef' => $shopRef, 'secret' => $secret, 'itemRefs' => array_keys($items)];

        return $this->receipt();
    }

    public function withdraw(string $shopRef, string $secret, array $itemRefs): ChannelReceipt
    {
        $this->withdrawals[] = ['shopRef' => $shopRef, 'secret' => $secret, 'itemRefs' => $itemRefs];

        return $this->receipt();
    }

    public function readStatuses(string $shopRef, string $secret, array $itemRefs): ?array
    {
        $this->reads[] = $itemRefs;

        if ($this->throwOnRead) {
            throw new RuntimeException('connection reset');
        }

        return $this->statuses;
    }

    private function receipt(): ChannelReceipt
    {
        if ($this->throwOnWrite) {
            throw new RuntimeException('connection reset');
        }

        return new ChannelReceipt($this->accept, $this->remoteRefs, $this->errors, $this->message);
    }
}
