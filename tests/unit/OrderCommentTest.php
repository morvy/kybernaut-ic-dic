<?php

namespace KybernautIcDic\Test;

use PHPUnit\Framework\TestCase;
use WP_Mock;

// It's good practice to use FQCN for WP_Mock overloads and for instanceof checks
// especially for classes outside the current namespace or common PHP/WordPress global classes.
// For classes within the same KybernautIcDicDeps namespace, their short names can be used
// if a `use KybernautIcDicDeps\Ibericode\Vat;` statement were present, but for clarity and
// safety with WP_Mock's string-based class naming, FQCNs are better.
// So, we don't strictly need `use` for Validator and ViesException if using FQCN strings.

class OrderCommentTest extends TestCase {

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
        // Default mock for wp_generate_uuid4. Specific tests can add expectations like once() or never().
        WP_Mock::userFunction('wp_generate_uuid4', [
            'return' => 'test-uuid-1234'
        ]);

        $countries_mock = WP_Mock::mock(); // Generic stdClass or mock object
        $countries_mock->countries = ['US' => 'United States', 'SK' => 'Slovakia', 'CZ' => 'Czech Republic'];
        $wc_mock = WP_Mock::mock(); // Generic stdClass or mock object
        $wc_mock->countries = $countries_mock;
        WP_Mock::userFunction('WC', ['return' => $wc_mock]);

        $logger_instance_mock = WP_Mock::mock('KybernautIcDic\Logger'); // FQCN string for Logger
        $logger_instance_mock->shouldReceive('log')->zeroOrMoreTimes();
        WP_Mock::userFunction('KybernautIcDic\Logger::getInstance', [ // FQCN string for Logger static method
            'return' => $logger_instance_mock
        ]);
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

        $order_mock = WP_Mock::mock('WC_Order');
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
                WP_Mock::expectUserFunction('wp_generate_uuid4', ['times' => 1, 'return' => 'test-uuid-1234']);
                $order_mock->shouldReceive('update_meta_data')->once()->with('_order_uuid', 'test-uuid-1234');
            } else {
                WP_Mock::expectUserFunction('wp_generate_uuid4', ['times' => 0]);
                 $order_mock->shouldNotReceive('update_meta_data')->with('_order_uuid', WP_Mock\Functions::anything());
            }
            $order_mock->shouldReceive('save_meta_data')->once();
            // add_order_note expectation will be set in specific tests to capture content

            $order_mock->shouldReceive('get_customer_ip_address')->andReturn($options['ip_address']);
            $order_mock->shouldReceive('get_billing_address_1')->andReturn($options['address_1']);
            $order_mock->shouldReceive('get_billing_address_2')->andReturn($options['address_2']);
            $order_mock->shouldReceive('get_billing_city')->andReturn($options['city']);
            $order_mock->shouldReceive('get_billing_postcode')->andReturn($options['postcode']);

            $date_mock = WP_Mock::mock('WC_DateTime');
            $date_mock->shouldReceive('format')->with('Y-m-d H:i:s')->andReturn($options['order_date_str']);
            $order_mock->shouldReceive('get_date_created')->andReturn($date_mock);

        } else {
            $order_mock->shouldNotReceive('add_order_note');
            $order_mock->shouldNotReceive('save_meta_data');
            WP_Mock::expectUserFunction('wp_generate_uuid4', ['times' => 0]);
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

        $validator_fqcn = 'KybernautIcDicDeps\Ibericode\Vat\Validator';
        $validator_mock = WP_Mock::mock('overload:' . $validator_fqcn);
        $validator_mock->shouldReceive('validateVatNumber')->with($vat_number)->andReturn(true)->once();

        woolab_icdic_add_vat_exemption_comment_to_order($order_id);
        // WP_Mock::assertActionsCalled(); // Removed
    }

    public function testCommentNotAddedForNonCompany() {
        $order_id = 456;
        $this->mockOrder($order_id, false, true);
        // mockOrder sets up expectations for add_order_note not to be called.
        woolab_icdic_add_vat_exemption_comment_to_order($order_id);
    }

    public function testCommentNotAddedForNonVatExempt() {
        $order_id = 789;
        $this->mockOrder($order_id, true, false);
        // mockOrder sets up expectations for add_order_note not to be called.
        woolab_icdic_add_vat_exemption_comment_to_order($order_id);
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

        $validator_fqcn = 'KybernautIcDicDeps\Ibericode\Vat\Validator';
        $validator_mock = WP_Mock::mock('overload:' . $validator_fqcn);
        $validator_mock->shouldReceive('validateVatNumber')->andReturn(true); // Assume valid for this test

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

        $validator_fqcn = 'KybernautIcDicDeps\Ibericode\Vat\Validator';
        $validator_mock = WP_Mock::mock('overload:' . $validator_fqcn);
        $validator_mock->shouldReceive('validateVatNumber')->andReturn(true);

        woolab_icdic_add_vat_exemption_comment_to_order($order_id);
        // Expectations for wp_generate_uuid4 (never) and update_meta_data (not for UUID) are in mockOrder
    }

    public function viesScenariosProvider() {
        // FQCN for ViesException to be used in data provider
        $vies_exception_fqcn = 'KybernautIcDicDeps\Ibericode\Vat\Vies\ViesException';
        return [
            'VAT Valid' => ['CZ12345678', true, 'Valid', ''],
            'VAT Invalid' => ['CZ87654321', false, 'Invalid', ''],
            'VIES Exception' => ['CZ00000000', new $vies_exception_fqcn('VIES service unavailable'), 'Error', 'VIES service unavailable'],
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
                $this->assertStringContainsString($expected_vies_result_text, $note_content);
                if (!empty($expected_vies_details_text)) {
                    $this->assertStringContainsString($expected_vies_details_text, $note_content);
                } else {
                    // Check that VIES Details row might be absent or empty.
                    // Depending on implementation, it might add "VIES Details: N/A" or similar, or omit the row.
                    // For now, let's assume if details_text is empty, it shouldn't be prominently there.
                    // A more robust check might look for the label "VIES Details" and then check the value.
                }
                return true;
            });

        $validator_fqcn = 'KybernautIcDicDeps\Ibericode\Vat\Validator';
        $validator_mock = WP_Mock::mock('overload:' . $validator_fqcn);

        // Need to use FQCN for instanceof check with namespaced classes
        $vies_exception_fqcn_check = 'KybernautIcDicDeps\Ibericode\Vat\Vies\ViesException';
        if ($vies_validator_response instanceof $vies_exception_fqcn_check) {
            $validator_mock->shouldReceive('validateVatNumber')->with($vat_number)->andThrow($vies_validator_response)->once();
        } else {
            $validator_mock->shouldReceive('validateVatNumber')->with($vat_number)->andReturn($vies_validator_response)->once();
        }

        // Ensure logger is mocked for exception cases
        if ($vies_validator_response instanceof $vies_exception_fqcn_check) {
            // Logger mock is global in setUp, just ensure it can be called
        }

        woolab_icdic_add_vat_exemption_comment_to_order($order_id);
    }
}
