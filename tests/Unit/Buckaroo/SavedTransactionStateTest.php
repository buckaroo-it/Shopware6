<?php

namespace Buckaroo\Shopware6\Tests\Unit\Buckaroo;

use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Buckaroo\Push\RequestType;
use Buckaroo\Shopware6\Buckaroo\TransactionState;
use Buckaroo\Shopware6\Buckaroo\Push\RequestStatus;
use Buckaroo\Shopware6\Buckaroo\SavedTransactionState;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseEntity;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseCollection;
use DateInterval;
use Faker\Core\DateTime;

class SavedTransactionStateTest extends TestCase
{

    public function testStateHasRefunds() {
        $state = new SavedTransactionState(
            $this->createEngineResponseCollection(
                [
                    [
                        "getType" => RequestType::PAYMENT,
                        "getStatus" => RequestStatus::SUCCESS,
                        "getAmount" => 10.00,
                        "getTransactionKey" => 'trans1',
                        "getUniqueIdentifier" => '2',
                        "getCreatedByEngineAt" => ((new \DateTime())->sub(new DateInterval('PT10S')))
                    ],
                [
                    "getType" => RequestType::REFUND,
                    "getStatus" => RequestStatus::SUCCESS,
                    "getAmount" => 10.00,
                    "getTransactionKey" => 'trans2',
                    "getRelatedTransaction" =>  "trans1",
                    "getUniqueIdentifier" => '1',
                    "getCreatedByEngineAt" => new \DateTime()
                ],
                [
                    "getType" => RequestType::REFUND,
                    "getStatus" => RequestStatus::FAILED,
                    "getAmount" => 10.00,
                    "getTransactionKey" => 'trans2',
                    "getUniqueIdentifier" => '2',
                    "getCreatedByEngineAt" => ((new \DateTime())->sub(new DateInterval('PT10S')))
                ]
                
                ]
            )
        );

        $this->assertCount(1, $state->getPayments());
        $this->assertTrue($state->hasPayments());

        $this->assertCount(1, $state->getRefunds());
        $this->assertTrue($state->hasRefunds());
    }
    private function createEngineResponseCollection(array $data) {
        $collection = new EngineResponseCollection();
        foreach ($data as $item) {
            $collection->add($this->createEngineResponseMock($item));
        }
        return $collection;
    }

    private function createEngineResponseMock(array $methodData)
    {
        $mock = $this->createMock(EngineResponseEntity::class);
        foreach ($methodData as $method => $value) {
            $mock->method($method)->willReturn($value);
        }
        return $mock;
    }
}