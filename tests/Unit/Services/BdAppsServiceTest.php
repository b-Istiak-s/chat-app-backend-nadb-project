<?php

namespace Tests\Unit\Services;

use App\Exceptions\BdApps\BdAppsException;
use App\Services\BdApps\BdAppsService;
use Tests\TestCase;

class BdAppsServiceTest extends TestCase
{
    private function service(): BdAppsService
    {
        return new BdAppsService();
    }

    public function test_local_018_becomes_tel_88018(): void
    {
        config()->set('bdapps.country_code', '880');

        $this->assertSame(
            'tel:8801812345678',
            $this->service()->formatSubscriberId('01812345678')
        );
    }

    public function test_international_88018_becomes_tel_88018(): void
    {
        config()->set('bdapps.country_code', '880');

        $this->assertSame(
            'tel:8801812345678',
            $this->service()->formatSubscriberId('8801812345678')
        );
    }

    public function test_international_8818_becomes_tel_88018(): void
    {
        config()->set('bdapps.country_code', '880');

        $this->assertSame(
            'tel:8801812345678',
            $this->service()->formatSubscriberId('881812345678')
        );
    }

    public function test_existing_tel_prefix_is_preserved(): void
    {
        config()->set('bdapps.country_code', '880');

        $this->assertSame(
            'tel:8801812345678',
            $this->service()->formatSubscriberId('tel:8801812345678')
        );
    }

    public function test_extract_local_phone_from_tel_subscriber_id(): void
    {
        config()->set('bdapps.country_code', '880');

        $this->assertSame(
            '01812345678',
            $this->service()->extractLocalPhone('tel:8801812345678')
        );
    }

    public function test_request_otp_posts_expected_payload(): void
    {
        config()->set('bdapps.country_code', '880');
        config()->set('bdapps.application_id', 'APP_137539');
        config()->set('bdapps.password', 'test-password');
        config()->set('bdapps.application_hash', 'ChatApp');
        config()->set('bdapps.base_url', 'https://developer.bdapps.com');
        config()->set('bdapps.otp_request_endpoint', '/subscription/otp/request');
        config()->set('bdapps.timeout_seconds', 30);
        config()->set('bdapps.verify_ssl', true);
        config()->set('bdapps.success_status_code', 'S1000');

        \Illuminate\Support\Facades\Http::fake([
            'developer.bdapps.com/*' => \Illuminate\Support\Facades\Http::response([
                'referenceNo' => 'REF-123',
                'statusCode' => 'S1000',
                'statusDetail' => 'Success',
            ], 200),
        ]);

        $result = $this->service()->requestOtp('01812345678');

        $this->assertTrue($result['ok']);
        $this->assertSame('REF-123', $result['reference_no']);
        $this->assertSame('S1000', $result['status_code']);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://developer.bdapps.com/subscription/otp/request'
                && $body['applicationId'] === 'APP_137539'
                && $body['subscriberId'] === 'tel:8801812345678'
                && $body['applicationHash'] === 'ChatApp'
                && isset($body['applicationMetaData']);
        });
    }

    public function test_request_otp_throws_bdapps_exception_on_gateway_error(): void
    {
        config()->set('bdapps.country_code', '880');
        config()->set('bdapps.application_id', 'APP_137539');
        config()->set('bdapps.password', 'test-password');
        config()->set('bdapps.application_hash', 'ChatApp');
        config()->set('bdapps.base_url', 'https://developer.bdapps.com');
        config()->set('bdapps.otp_request_endpoint', '/subscription/otp/request');
        config()->set('bdapps.timeout_seconds', 30);
        config()->set('bdapps.verify_ssl', true);
        config()->set('bdapps.success_status_code', 'S1000');

        \Illuminate\Support\Facades\Http::fake([
            'developer.bdapps.com/*' => \Illuminate\Support\Facades\Http::response([
                'statusCode' => 'E1312',
                'statusDetail' => 'Request is Invalid.',
                'version' => '1.0',
            ], 200),
        ]);

        try {
            $this->service()->requestOtp('01812345678');
            $this->fail('Expected BdAppsException was not thrown.');
        } catch (BdAppsException $e) {
            $this->assertSame('E1312', $e->statusCode);
            $this->assertSame('Request is Invalid.', $e->statusDetail);
            $this->assertSame(200, $e->httpStatus);
            $this->assertStringContainsString('otp request', $e->getMessage());
        }
    }

    public function test_verify_otp_throws_bdapps_exception_on_wrong_otp(): void
    {
        config()->set('bdapps.country_code', '880');
        config()->set('bdapps.application_id', 'APP_137539');
        config()->set('bdapps.password', 'test-password');
        config()->set('bdapps.base_url', 'https://developer.bdapps.com');
        config()->set('bdapps.otp_verify_endpoint', '/subscription/otp/verify');
        config()->set('bdapps.timeout_seconds', 30);
        config()->set('bdapps.verify_ssl', true);
        config()->set('bdapps.success_status_code', 'S1000');

        \Illuminate\Support\Facades\Http::fake([
            'developer.bdapps.com/*' => \Illuminate\Support\Facades\Http::response([
                'statusCode' => 'E1325',
                'statusDetail' => 'Invalid OTP.',
                'version' => '1.0',
            ], 200),
        ]);

        try {
            $this->service()->verifyOtp('REF-123', '000000');
            $this->fail('Expected BdAppsException was not thrown.');
        } catch (BdAppsException $e) {
            $this->assertSame('E1325', $e->statusCode);
            $this->assertSame('Invalid OTP.', $e->statusDetail);
        }
    }

    public function test_unsubscribe_posts_action_zero(): void
    {
        config()->set('bdapps.country_code', '880');
        config()->set('bdapps.application_id', 'APP_137539');
        config()->set('bdapps.password', 'test-password');
        config()->set('bdapps.base_url', 'https://developer.bdapps.com');
        config()->set('bdapps.subscription_endpoint', '/subscription/send');
        config()->set('bdapps.timeout_seconds', 30);
        config()->set('bdapps.verify_ssl', true);
        config()->set('bdapps.success_status_code', 'S1000');

        \Illuminate\Support\Facades\Http::fake([
            'developer.bdapps.com/*' => \Illuminate\Support\Facades\Http::response([
                'subscriptionStatus' => 'UNREGISTERED',
                'statusCode' => 'S1000',
                'statusDetail' => 'Success',
            ], 200),
        ]);

        $result = $this->service()->unsubscribe('01812345678');

        $this->assertTrue($result['ok']);
        $this->assertSame('UNREGISTERED', $result['subscription_status']);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['action'] === '0'
                && $body['version'] === '1.0'
                && $body['subscriberId'] === 'tel:8801812345678';
        });
    }

    public function test_unsubscribe_throws_when_gateway_rejects(): void
    {
        config()->set('bdapps.country_code', '880');
        config()->set('bdapps.application_id', 'APP_137539');
        config()->set('bdapps.password', 'test-password');
        config()->set('bdapps.base_url', 'https://developer.bdapps.com');
        config()->set('bdapps.subscription_endpoint', '/subscription/send');
        config()->set('bdapps.timeout_seconds', 30);
        config()->set('bdapps.verify_ssl', true);
        config()->set('bdapps.success_status_code', 'S1000');

        \Illuminate\Support\Facades\Http::fake([
            'developer.bdapps.com/*' => \Illuminate\Support\Facades\Http::response([
                'statusCode' => 'E1631',
                'statusDetail' => 'Subscriber not allow-listed.',
                'version' => '1.0',
            ], 200),
        ]);

        try {
            $this->service()->unsubscribe('01812345678');
            $this->fail('Expected BdAppsException was not thrown.');
        } catch (BdAppsException $e) {
            $this->assertSame('E1631', $e->statusCode);
            $this->assertSame('Subscriber not allow-listed.', $e->statusDetail);
        }
    }
}