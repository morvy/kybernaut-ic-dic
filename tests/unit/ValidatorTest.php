<?php

namespace KybernautIcDic\Test;

use Brain\Monkey\Filters;
use KybernautIcDic\Services\AbstractService;
use KybernautIcDic\Services\FinancnaSprava\Importer;
use KybernautIcDic\Services\Services;
use KybernautIcDic\Validation\AbstractCheck;
use KybernautIcDic\Validation\Check;
use KybernautIcDic\Validation\Context;
use KybernautIcDic\Validation\Result;
use KybernautIcDic\Validation\Validator;

/**
 * A Polish store's NIP check: in Poland, its own register instead of VIES.
 */
final class PolishWhitelist extends AbstractCheck {

	protected string $field = 'dic';

	public function id(): string {
		return 'vat_id_vies';
	}

	public function applies( Context $context ): bool {
		return 'PL' === $context->country() && $context->entered( 'dic' );
	}

	public function run( Context $context, Result $result ): void {
		if ( 'PL5260250995' !== $context->number( 'dic' ) ) {
			$result->add_error( $this->field(), 'pl_not_on_whitelist', array(), 'This NIP is not on the VAT whitelist.' );
		}
	}
}

/**
 * A Polish company register: knows one company.
 */
final class FakeGus extends AbstractService {

	public int $asked = 0;

	public function id(): string {
		return 'gus';
	}

	public function lookup_company( string $ico ): ?array {
		++$this->asked;
		return '5260250995' === $ico ? array( 'error' => false ) : array( 'error' => 'Not in GUS.' );
	}
}

/**
 * The extension points of the checks (src/Validation) and the registers (src/Registry).
 */
class ValidatorTest extends TestCase {

	private function context( array $over = array() ): Context {
		return new Context(
			array_merge(
				array(
					'country'             => 'PL',
					'ic'                  => '',
					'dic'                 => 'PL1234567890',
					'dic_present'         => true,
					'dic_dph'             => '',
					'company'             => 'Firma sp. z o.o.',
					'vies_check'          => true,
					'check_country_match' => true,
					'country_in_eu'       => true,
					'verify_vat'          => static fn () => 'invalid',
				),
				$over
			)
		);
	}

	private static function codes( Result $result ): array {
		return array_column( $result->errors(), 'code' );
	}

	public function testEveryBuiltInCheckHasItsId(): void {
		$checks = Validator::default_checks();

		$this->assertCount( 13, $checks );
		foreach ( $checks as $id => $check ) {
			$this->assertInstanceOf( Check::class, $check );
			$this->assertSame( $id, $check->id() );
			$this->assertContains( $check->field(), array( 'company', 'ic', 'dic', 'dic_dph' ) );
		}
	}

	public function testFindingsSayTheirField(): void {
		$errors = Validator::run( $this->context() )->errors();

		$this->assertSame( array( 'invalid_vat' ), array_column( $errors, 'code' ) );
		$this->assertSame( 'dic', $errors[0]['field'] );
	}

	public function testAStoreReplacesACheck(): void {
		Filters\expectApplied( 'woolab_icdic_checks' )->once()->andReturnUsing(
			static function ( array $checks ) {
				$checks['vat_id_vies'] = new PolishWhitelist();
				return $checks;
			}
		);

		$errors = Validator::run( $this->context() )->errors();

		$this->assertSame( array( 'pl_not_on_whitelist' ), array_column( $errors, 'code' ) );
		$this->assertSame( 'This NIP is not on the VAT whitelist.', $errors[0]['message'] );
	}

	public function testAStoreAddsACheckOfItsOwn(): void {
		Filters\expectApplied( 'woolab_icdic_checks' )->once()->andReturnUsing(
			static fn ( array $checks ) => $checks + array( 'pl_whitelist' => new PolishWhitelist() )
		);

		// Both run: VIES says invalid, the store's register does not list it either.
		$this->assertSame( array( 'invalid_vat', 'pl_not_on_whitelist' ), self::codes( Validator::run( $this->context() ) ) );
	}

	public function testAStoreSwitchesACheckOff(): void {
		Filters\expectApplied( 'woolab_icdic_check_applies' )->andReturnUsing(
			static fn ( $applies, Check $check ) => 'vat_id_vies' === $check->id() ? false : $applies
		);

		$this->assertSame( array(), self::codes( Validator::run( $this->context() ) ) );
	}

	public function testAStoreLetsAFindingThrough(): void {
		Filters\expectApplied( 'woolab_icdic_check_result' )->andReturnUsing(
			static function ( Result $found ) {
				$found->remove( 'invalid_vat' );
				return $found;
			}
		);

		$this->assertSame( array(), self::codes( Validator::run( $this->context() ) ) );
	}

	public function testTheFinalResultIsFilteredOnce(): void {
		Filters\expectApplied( 'woolab_icdic_validation_result' )->once()->with( \Mockery::type( Result::class ), \Mockery::type( Context::class ) );

		Validator::run( $this->context() );
	}

	public function testARegisterIsAskedOncePerNumber(): void {
		$asked   = 0;
		$context = $this->context(
			array(
				'country'             => 'SK',
				'ic'                  => '36421928',
				'dic'                 => '2020317057',
				'dic_dph'             => 'SK2020317057',
				'sk_check'            => true,
				'check_dic_dph_match' => true,
				'verify_vat'          => static fn () => 'valid',
				'lookup_sk_ico'       => static fn () => array( 'error' => false ),
				'lookup_sk'           => static function ( $list ) use ( &$asked ) {
					++$asked;
					return array( 'ico' => '36421928' );
				},
			)
		);

		$this->assertSame( array(), self::codes( Validator::run( $context ) ) );
		// The DIČ row (TaxIdRegistry, IcDphTaxIdMatch) and the IČ DPH row, once each.
		$this->assertSame( 2, $asked );
	}

	public function testTheLegacyArrayKeepsItsShape(): void {
		$result = woolab_icdic_validate_checkout(
			array(
				'country'       => 'PL',
				'dic'           => 'PL1234567890',
				'country_in_eu' => true,
				'vies_check'    => true,
				'verify_vat'    => static fn () => 'invalid',
			)
		);

		// Entries without the field, as in 1.x and 2.0 before the checks.

		$this->assertSame( array( array( 'code' => 'invalid_vat' ) ), $result['errors'] );
	}

	public function testAStoresServiceChecksTheIcoOfItsCountry(): void {
		$gus      = new FakeGus();
		$services = array(
			'company_lookup' => array( $gus ),
			'vat_id'         => array( new \KybernautIcDic\Services\CallbackService( 'vies', array( 'vat_id' => static fn () => array( 'state' => 'invalid', 'ico' => '' ) ) ) ),
		);

		$found = Validator::run( $this->context( array( 'ic' => '1234567890', 'services' => $services ) ) );
		$this->assertSame( array( 'invalid_business_id', 'invalid_vat' ), self::codes( $found ) );
		$this->assertSame( 'Not in GUS.', $found->errors()[0]['data']['ares_message'] );
		$this->assertSame( 'ic', $found->errors()[0]['field'] );

		$this->assertSame( array( 'invalid_vat' ), self::codes( Validator::run( $this->context( array( 'ic' => '5260250995', 'services' => $services ) ) ) ) );
		$this->assertSame( 2, $gus->asked );
	}

	public function testTheMapFollowsTheSettings(): void {
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array( 'ares_check' => 'yes', 'ares_fill' => 'yes', 'sk_check' => 'yes', 'vies_check' => 'yes' ) );

		$sk = Services::for_country( 'SK' );
		$this->assertSame( array( 'financna_sprava', 'vies' ), array_map( static fn ( $service ) => $service->id(), $sk['vat_id'] ) );
		$this->assertArrayNotHasKey( 'company_search', $sk ); // sk_fill off.
		$this->assertSame( 'ares', Services::for_country( 'CZ' )['company_search'][0]->id() );
		// Every other country: VIES only ('*').
		$this->assertSame( array( 'vat_id' ), array_keys( Services::for_country( 'DE' ) ) );
		$this->assertTrue( Importer::needed() );
	}

	public function testAStoreReplacesFinancnaSprava(): void {
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array( 'sk_check' => 'yes', 'vies_check' => 'yes' ) );
		$mine = new FakeGus();
		Filters\expectApplied( 'woolab_icdic_services' )->andReturnUsing(
			static function ( array $map ) use ( $mine ) {
				foreach ( Services::FEATURES as $feature ) {
					$map['SK'][ $feature ] = array( $mine );
				}
				return $map;
			}
		);

		$this->assertSame( $mine, Services::for_country( 'SK' )['company_lookup'][0] );
		$this->assertSame( array( 'CZ', 'SK' ), Services::countries( 'company_lookup' ) ); // ARES is on by default.
		// No feature uses the lists any more: their import is not needed.
		$this->assertFalse( Importer::needed() );
	}
}
