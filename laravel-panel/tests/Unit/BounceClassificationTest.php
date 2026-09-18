<?php

namespace Tests\Unit;

use App\Services\Abuse\BounceClassificationService;
use App\Services\Abuse\NormalizedMailEvent;
use PHPUnit\Framework\TestCase;

class BounceClassificationTest extends TestCase
{
    private BounceClassificationService $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new BounceClassificationService();
    }

    public function test_classifies_standard_delivery_success(): void
    {
        $event = new NormalizedMailEvent(
            status: 'sent',
            dsn: '2.0.0',
            smtpCode: 250
        );

        $result = $this->classifier->classify($event);
        $this->assertEquals(BounceClassificationService::CLASSIFICATION_SUCCESS, $result);
        $this->assertTrue($this->classifier->isSuccess($result));
    }

    public function test_classifies_success_with_missing_dsn(): void
    {
        $result = $this->classifier->classifyFields('sent', null, 250);
        $this->assertEquals(BounceClassificationService::CLASSIFICATION_SUCCESS, $result);
    }

    public function test_classifies_hard_bounce_with_5xx_dsn(): void
    {
        $event = new NormalizedMailEvent(
            status: 'bounced',
            dsn: '5.1.1',
            smtpCode: 550
        );

        $result = $this->classifier->classify($event);
        $this->assertEquals(BounceClassificationService::CLASSIFICATION_HARD_BOUNCE, $result);
        $this->assertTrue($this->classifier->isHardBounce($result));
        $this->assertFalse($this->classifier->isSoftBounce($result));
    }

    public function test_classifies_hard_bounce_with_smtp_554_missing_dsn(): void
    {
        $result = $this->classifier->classifyFields('bounced', null, 554);
        $this->assertEquals(BounceClassificationService::CLASSIFICATION_HARD_BOUNCE, $result);
    }

    public function test_classifies_soft_bounce_for_deferred_status(): void
    {
        $event = new NormalizedMailEvent(
            status: 'deferred',
            dsn: '4.4.1',
            smtpCode: null
        );

        $result = $this->classifier->classify($event);
        $this->assertEquals(BounceClassificationService::CLASSIFICATION_SOFT_BOUNCE, $result);
        $this->assertTrue($this->classifier->isSoftBounce($result));
    }

    public function test_classifies_soft_bounce_for_4xx_dsn_on_bounce(): void
    {
        // Occurs when queue maximum lifetime expires on a deferred message
        $result = $this->classifier->classifyFields('bounced', '4.4.7', 451);
        $this->assertEquals(BounceClassificationService::CLASSIFICATION_SOFT_BOUNCE, $result);
    }

    public function test_classifies_soft_bounce_with_smtp_450_missing_dsn(): void
    {
        $result = $this->classifier->classifyFields('bounced', null, 450);
        $this->assertEquals(BounceClassificationService::CLASSIFICATION_SOFT_BOUNCE, $result);
    }

    public function test_classifies_unknown_on_empty_or_missing_status(): void
    {
        $this->assertEquals(
            BounceClassificationService::CLASSIFICATION_UNKNOWN,
            $this->classifier->classifyFields(null, null, null)
        );

        $this->assertEquals(
            BounceClassificationService::CLASSIFICATION_UNKNOWN,
            $this->classifier->classifyFields('', '5.1.1', 550)
        );
    }

    public function test_classifies_unknown_on_conflicting_status_and_dsn(): void
    {
        // status=sent with 5.1.1 is contradictory and ambiguous
        $result = $this->classifier->classifyFields('sent', '5.1.1', 550);
        $this->assertEquals(BounceClassificationService::CLASSIFICATION_UNKNOWN, $result);
    }
}
