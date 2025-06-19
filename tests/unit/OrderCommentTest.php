<?php

namespace KybernautIcDic\Test;

use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Test class for woolab_icdic_add_vat_exemption_comment_to_order function.
 * 
 * This class tests the VAT exemption comment functionality that adds detailed
 * VAT validation information to WooCommerce orders when they are VAT exempt.
 */
class OrderCommentTest extends TestCase {

    public static function setUpBeforeClass(): void {
        // For CI compatibility, load dependencies gracefully but don't force them
        $deps_paths = [
            __DIR__ . '/../../deps/scoper-autoload.php',
            __DIR__ . '/../../deps/autoload.php',
        ];
        
        foreach ($deps_paths as $path) {
            if (file_exists($path)) {
                try {
                    require_once $path;
                } catch (\Throwable $e) {
                    // Continue silently
                }
            }
        }
    }

    public function setUp(): void {
        WP_Mock::setUp();
        WP_Mock::userFunction('__', [
            'return' => function($text, $domain) {
                return $text;
            }
        ]);
        WP_Mock::userFunction('esc_html', [
            'return' => function($text) {
                return $text;
            }
        ]);
        // Mock wp_parse_args
        WP_Mock::userFunction('wp_parse_args', [
            'return' => function($args, $defaults = []) {
                if (is_array($args)) {
                    return array_merge($defaults, $args);
                }
                return $defaults;
            }
        ]);
        // Default mock for wp_generate_uuid4. Specific tests can add expectations like once() or never().
        WP_Mock::userFunction('wp_generate_uuid4', [
            'return' => 'test-uuid-1234'
        ]);

        $countries_mock = \Mockery::mock(); 
        $countries_mock->countries = ['US' => 'United States', 'SK' => 'Slovakia', 'CZ' => 'Czech Republic'];
        $wc_mock = \Mockery::mock(); 
        $wc_mock->countries = $countries_mock;
        WP_Mock::userFunction('WC', ['return' => $wc_mock]);

        // Mock wp_remote_get for VAT validation HTTP requests
        WP_Mock::userFunction('wp_remote_get', [
            'return' => [
                'response' => ['code' => 200],
                'body' => '{"valid": true}'
            ]
        ]);

        // Additional WordPress functions that might be called in CI
        WP_Mock::userFunction('wp_remote_retrieve_response_code', [
            'return' => 200
        ]);
        WP_Mock::userFunction('wp_remote_retrieve_body', [
            'return' => '{"valid": true}'
        ]);
        WP_Mock::userFunction('is_wp_error', [
            'return' => false
        ]);

        // Mock Logger getInstance static method differently
        $logger_instance_mock = \Mockery::mock(); 
        $logger_instance_mock->shouldReceive('log')->zeroOrMoreTimes();
        
        // Try to avoid the :: in function names by creating a simpler mock
        // We'll just let the logger work without mocking the static method
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
    }

    protected function mockOrder($order_id, $is_company, $is_vat_exempt, $options = []) {
        $options = wp_parse_args($options, [
            'billing_country' => 'CZ',
            'vat_number_key' => '_billing_dic', // This will be _billing_dic or _billing_dic_dph
            'vat_number_value' => 'CZ12345678',
            'existing_uuid' => null,
            'company_name' => 'Test Company',
            'billing_ic' => '12345678',
            'ip_address' => '127.0.0.1',
            'address_1' => '123 Main St',
            'address_2' => 'Suite 100',
            'city' => 'Anytown',
            'postcode' => '12345',
            'order_date_str' => '2023-10-26 10:00:00'
        ]);

        $order_mock = \Mockery::mock('WC_Order');
        $order_mock->shouldReceive('get_id')->andReturn($order_id);
        $order_mock->shouldReceive('get_billing_company')->andReturn($is_company ? $options['company_name'] : '');
        $order_mock->shouldReceive('get_is_vat_exempt')->andReturn($is_vat_exempt);
        $order_mock->shouldReceive('get_billing_country')->andReturn($options['billing_country']);

        // Revised get_meta mocking
        $order_mock->shouldReceive('get_meta')->zeroOrMoreTimes()->andReturnUsing(
            function($key) use ($options) {
                if ($key === '_order_uuid') {
                    return $options['existing_uuid'];
                }
                // Determine the correct VAT number key based on country for the actual function call
                $actual_vat_key_for_function = ($options['billing_country'] === 'SK') ? '_billing_dic_dph' : '_billing_dic';

                if ($key === $actual_vat_key_for_function) {
                    return $options['vat_number_value'];
                }
                if ($key === '_billing_ic') {
                    return $options['billing_ic'];
                }
                // If SK, and the primary VAT key is _billing_dic_dph, a call to _billing_dic might still occur
                if ($options['billing_country'] === 'SK' && $key === '_billing_dic' && $actual_vat_key_for_function === '_billing_dic_dph') {
                    return 'SK_NON_VAT_DIC_123'; // Default for SK's non-VAT Tax ID if _billing_dic is asked for
                }
                return null;
            }
        );

        if ($is_company && $is_vat_exempt) {
            if (is_null($options['existing_uuid'])) {
                // Set expectation for UUID generation when UUID doesn't exist
                WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'test-uuid-1234']);
                $order_mock->shouldReceive('update_meta_data')->once()->with('_order_uuid', 'test-uuid-1234');
            } else {
                // UUID already exists, so update_meta_data for UUID should not be called
                $order_mock->shouldNotReceive('update_meta_data')->with('_order_uuid', \Mockery::any());
            }
            $order_mock->shouldReceive('save_meta_data')->once();
            // add_order_note expectation will be set in specific tests to capture content

            $order_mock->shouldReceive('get_customer_ip_address')->andReturn($options['ip_address']);
            $order_mock->shouldReceive('get_billing_address_1')->andReturn($options['address_1']);
            $order_mock->shouldReceive('get_billing_address_2')->andReturn($options['address_2']);
            $order_mock->shouldReceive('get_billing_city')->andReturn($options['city']);
            $order_mock->shouldReceive('get_billing_postcode')->andReturn($options['postcode']);

            $date_mock = \Mockery::mock();
            $date_mock->shouldReceive('format')->with('Y-m-d H:i:s')->andReturn($options['order_date_str']);
            $order_mock->shouldReceive('get_date_created')->andReturn($date_mock);

        } else {
            $order_mock->shouldNotReceive('add_order_note');
            $order_mock->shouldNotReceive('save_meta_data');
        }

        WP_Mock::userFunction('wc_get_order', [
            'args' => [$order_id],
            'return' => $order_mock
        ]);
        return $order_mock;
    }

    public function testCommentAddedForVatExemptCompany() {
        $order_id = 123;
        $vat_number = 'CZ12345678';
        $order_mock = $this->mockOrder($order_id, true, true, [
            'billing_country' => 'CZ',
            'vat_number_key' => '_billing_dic', // Not strictly needed by mockOrder's new logic but good for clarity
            'vat_number_value' => $vat_number,
            'existing_uuid' => null // Test UUID generation path
        ]);

        $order_mock->shouldReceive('add_order_note')
            ->once()
            ->withArgs(function($note_content, $is_private) use ($order_id, $vat_number) {
                $this->assertFalse($is_private);
                $this->assertStringContainsString('<h3>VAT Exemption Details</h3>', $note_content);
                $this->assertStringContainsString(__('Order ID', 'woolab-ic-dic'), $note_content);
                $this->assertStringContainsString(strval($order_id), $note_content);
                $this->assertStringContainsString('test-uuid-1234', $note_content);
                $this->assertStringContainsString(__('Company Name', 'woolab-ic-dic'), $note_content);
                $this->assertStringContainsString('Test Company', $note_content);
                $this->assertStringContainsString(__('VAT Number', 'woolab-ic-dic'), $note_content);
                $this->assertStringContainsString($vat_number, $note_content);
                $this->assertStringContainsString(__('VIES Validation Result', 'woolab-ic-dic'), $note_content);
                $this->assertStringContainsString(__('Valid', 'woolab-ic-dic'), $note_content);
                $this->assertStringContainsString('123 Main St, Suite 100, Anytown, 12345, Czech Republic', $note_content);
                return true;
            });

        // Create a mock for the scoped Validator class - CI-safe approach
        try {
            // Try overload first (works when class exists via autoloader)
            $validatorMock = \Mockery::mock('overload:KybernautIcDicDeps\Ibericode\Vat\Validator');
        } catch (\Exception $e) {
            // Fallback to alias for CI environments
            $validatorMock = \Mockery::mock('alias:KybernautIcDicDeps\Ibericode\Vat\Validator');
        }
        $validatorMock->shouldReceive('validateVatNumber')->with($vat_number)->andReturn(true)->once();

        woolab_icdic_add_vat_exemption_comment_to_order($order_id);
        // WP_Mock::assertActionsCalled(); // Removed
    }

    public function testCommentNotAddedForNonCompany() {
        $order_id = 456;
        $this->mockOrder($order_id, false, true);
        // mockOrder sets up expectations for add_order_note not to be called.
        woolab_icdic_add_vat_exemption_comment_to_order($order_id);
        
        // Add assertion to verify the function ran (indirect verification)
        $this->assertTrue(true, 'Function completed without errors');
    }

    public function testCommentNotAddedForNonVatExempt() {
        $order_id = 789;
        $this->mockOrder($order_id, true, false);
        // mockOrder sets up expectations for add_order_note not to be called.
        woolab_icdic_add_vat_exemption_comment_to_order($order_id);
        
        // Add assertion to verify the function ran (indirect verification)
        $this->assertTrue(true, 'Function completed without errors');
    }

    public function testOrderUuidIsGeneratedAndUsedWhenNotPresent() {
        $order_id = 201;
        $order_mock = $this->mockOrder($order_id, true, true, ['existing_uuid' => null]);

        $order_mock->shouldReceive('add_order_note')
            ->once()
            ->withArgs(function($note_content, $is_private) {
                $this->assertStringContainsString('test-uuid-1234', $note_content);
                return true;
            });

        try {
            $validatorMock = \Mockery::mock('overload:KybernautIcDicDeps\Ibericode\Vat\Validator');
        } catch (\Exception $e) {
            $validatorMock = \Mockery::mock('alias:KybernautIcDicDeps\Ibericode\Vat\Validator');
        }
        $validatorMock->shouldReceive('validateVatNumber')->andReturn(true); // Assume valid for this test

        woolab_icdic_add_vat_exemption_comment_to_order($order_id);
        // Expectations for wp_generate_uuid4 (once) and update_meta_data (once) are in mockOrder
    }

    public function testOrderUuidIsUsedWhenPresent() {
        $order_id = 202;
        $existing_uuid = 'existing-uuid-5678';
        $order_mock = $this->mockOrder($order_id, true, true, ['existing_uuid' => $existing_uuid]);

        $order_mock->shouldReceive('add_order_note')
            ->once()
            ->withArgs(function($note_content, $is_private) use ($existing_uuid) {
                $this->assertStringContainsString($existing_uuid, $note_content);
                $this->assertStringNotContainsString('test-uuid-1234', $note_content);
                return true;
            });

        try {
            $validatorMock = \Mockery::mock('overload:KybernautIcDicDeps\Ibericode\Vat\Validator');
        } catch (\Exception $e) {
            $validatorMock = \Mockery::mock('alias:KybernautIcDicDeps\Ibericode\Vat\Validator');
        }
        $validatorMock->shouldReceive('validateVatNumber')->andReturn(true);

        woolab_icdic_add_vat_exemption_comment_to_order($order_id);
        // Expectations for wp_generate_uuid4 (never) and update_meta_data (not for UUID) are in mockOrder
    }

    public function viesScenariosProvider() {
        return [
            'VAT Valid' => ['CZ12345678', true, 'Valid', ''],
            'VAT Invalid' => ['CZ87654321', false, 'Invalid', ''],
            'VIES Exception' => ['CZ00000000', 'exception', 'Error', 'VIES service unavailable'],
        ];
    }

    /**
     * @dataProvider viesScenariosProvider
     */
    public function testViesValidationScenarios($vat_number, $vies_validator_response, $expected_vies_result_text, $expected_vies_details_text) {
        $order_id = 301;
        $order_mock = $this->mockOrder($order_id, true, true, [
            'vat_number_value' => $vat_number
        ]);

        $order_mock->shouldReceive('add_order_note')
            ->once()
            ->withArgs(function($note_content, $is_private) use ($expected_vies_result_text, $expected_vies_details_text) {
                $this->assertFalse($is_private);
                $this->assertStringContainsString($expected_vies_result_text, $note_content);
                if (!empty($expected_vies_details_text)) {
                    $this->assertStringContainsString($expected_vies_details_text, $note_content);
                }
                return true;
            });

        // Create mock for the scoped Validator class
        try {
            $validatorMock = \Mockery::mock('overload:KybernautIcDicDeps\Ibericode\Vat\Validator');
        } catch (\Exception $e) {
            $validatorMock = \Mockery::mock('alias:KybernautIcDicDeps\Ibericode\Vat\Validator');
        }

        // Check if response is an exception (using string marker)
        if ($vies_validator_response === 'exception') {
            // Use generic Exception for CI compatibility - the function catches any exception
            $exception = new \Exception($expected_vies_details_text);
            $validatorMock->shouldReceive('validateVatNumber')->with($vat_number)->andThrow($exception)->once();
        } else {
            $validatorMock->shouldReceive('validateVatNumber')->with($vat_number)->andReturn($vies_validator_response)->once();
        }

        woolab_icdic_add_vat_exemption_comment_to_order($order_id);
    }
}
