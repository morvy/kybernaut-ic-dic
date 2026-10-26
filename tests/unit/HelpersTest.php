<?php

namespace KybernautIcDic\Test;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the mathematical validation helpers in includes/helpers.php.
 *
 * All functions under test are pure (no WordPress dependencies),
 * so they need no WordPress functions.
 */
class HelpersTest extends TestCase {

    #[DataProvider( 'providerIc' )]
    public function testVerifyIc( $ic, $expected ) {
        $this->assertSame( $expected, (bool) woolab_icdic_verify_ic( $ic ), "IC: {$ic}" );
    }

    public static function providerIc() {
        return [
            'valid (Alza)'              => [ '27082440', true ],
            'valid (Česká pošta)'       => [ '47114983', true ],
            'valid with spaces'         => [ '270 824 40', true ],
            'invalid checksum'          => [ '27082441', false ],
            'too short'                 => [ '1234567', false ],
            'too long'                  => [ '123456789', false ],
            'non-numeric'               => [ 'abcdefgh', false ],
            'empty'                     => [ '', false ],
        ];
    }

    #[DataProvider( 'providerRc' )]
    public function testVerifyRc( $rc, $expected ) {
        $this->assertSame( $expected, (bool) woolab_icdic_verify_rc( $rc ), "RC: {$rc}" );
    }

    public static function providerRc() {
        return [
            'valid male with slash'     => [ '740104/0020', true ],
            'valid male without slash'  => [ '7401040020', true ],
            'valid female (month +50)'  => [ '745104/0025', true ],
            'valid 9-digit pre-1954'    => [ '530101/123', true ],
            'invalid checksum'          => [ '740104/0021', false ],
            'invalid date (Feb 30)'     => [ '530230/123', false ],
            'garbage'                   => [ 'abc', false ],
            'empty'                     => [ '', false ],
        ];
    }

    #[DataProvider( 'providerDic' )]
    public function testVerifyDic( $dic, $expected ) {
        $this->assertSame( $expected, (bool) woolab_icdic_verify_dic( $dic ), "DIC: {$dic}" );
    }

    public static function providerDic() {
        return [
            // 8 digits, legal entities (same modulo-11 scheme as IČO).
            'legal entity valid'        => [ '27082440', true ],
            'legal entity invalid'      => [ '27082441', false ],
            // 9 digits, individuals (RC-based, no check digit).
            'individual standard valid' => [ '440104002', true ],
            // 9 digits starting with 6, special cases with lookup-table check digit.
            'individual special valid'  => [ '640903926', true ],
            'individual special invalid'=> [ '640903925', false ],
            // 10 digits, individuals.
            '10-digit valid'            => [ '7401040020', true ],
            '10-digit invalid'          => [ '7401040021', false ],
            'garbage'                   => [ 'ABC', false ],
            'too short'                 => [ '1234', false ],
            'empty'                     => [ '', false ],
        ];
    }

    #[DataProvider( 'providerDicSk' )]
    public function testVerifyDicSk( $dic, $expected ) {
        $this->assertSame( $expected, (bool) woolab_icdic_verify_dic_sk( $dic ), "SK DIC: {$dic}" );
    }

    public static function providerDicSk() {
        return [
            // Divisible by 11, so the case stays valid once the
            // checksum TODO in woolab_icdic_verify_dic_sk() is implemented.
            'valid 10 digits'           => [ '2020317057', true ],
            'valid with spaces'         => [ '20 2031 7057', true ],
            'too short'                 => [ '123456789', false ],
            'too long'                  => [ '12345678901', false ],
            'non-numeric'               => [ 'abcdefghij', false ],
            'with SK prefix'            => [ 'SK2020317057', false ],
            'empty'                     => [ '', false ],
        ];
    }

    #[DataProvider( 'providerDicDphSk' )]
    public function testVerifyDicDphSk( $dic_dph, $expected ) {
        $this->assertSame( $expected, (bool) woolab_icdic_verify_dic_dph_sk( $dic_dph ), "SK DIC DPH: {$dic_dph}" );
    }

    public static function providerDicDphSk() {
        return [
            'valid'                     => [ 'SK2020317057', true ],
            'valid with spaces'         => [ 'SK 2020 317 057', true ],
            'invalid checksum'          => [ 'SK2020317058', false ],
            'wrong prefix'              => [ 'CZ2020317057', false ],
            'missing prefix'            => [ '2020317057', false ],
            'too short'                 => [ 'SK123', false ],
            'empty'                     => [ '', false ],
        ];
    }

    #[DataProvider( 'providerVatCountryCode' )]
    public function testGetVatNumberCountryCode( $vat_number, $expected ) {
        $this->assertSame( $expected, woolab_icdic_get_vat_number_country_code( $vat_number ) );
    }

    public static function providerVatCountryCode() {
        return [
            'Czech'                     => [ 'CZ27082440', 'CZ' ],
            'Slovak'                    => [ 'SK2020317057', 'SK' ],
            'Greek EL maps to GR'       => [ 'EL123456789', 'GR' ],
        ];
    }

    #[DataProvider( 'providerVatFormat' )]
    public function testVatFormat( $vat, $expected ) {
        $this->assertSame( $expected, \KybernautIcDic\Numbers\Checksum::vat_format( $vat ), "VAT: {$vat}" );
    }

    public static function providerVatFormat() {
        return [
            'AT valid'              => [ 'ATU12345678', true ],
            'AT invalid'            => [ 'AT12345678', false ],
            'BE valid'              => [ 'BE0123456789', true ],
            'BE invalid'            => [ 'BE2123456789', false ],
            'BG valid'              => [ 'BG123456789', true ],
            'BG invalid'            => [ 'BG12345678', false ],
            'CY valid'              => [ 'CY12345678L', true ],
            'CY invalid'            => [ 'CY123456789', false ],
            'CZ valid'              => [ 'CZ12345678', true ],
            'CZ invalid'            => [ 'CZ1234567', false ],
            'DE valid'              => [ 'DE123456789', true ],
            'DE invalid'            => [ 'DE12345678', false ],
            'DK valid'              => [ 'DK12345678', true ],
            'DK invalid'            => [ 'DK1234567', false ],
            'EE valid'              => [ 'EE123456789', true ],
            'EE invalid'            => [ 'EE12345678', false ],
            'EL valid'              => [ 'EL123456789', true ],
            'EL invalid'            => [ 'EL1234567890', false ],
            'ES valid'              => [ 'ESX1234567X', true ],
            'ES invalid'            => [ 'ESX12345678X', false ],
            'EU valid'              => [ 'EU123456789', true ],
            'EU invalid'            => [ 'EU12345678', false ],
            'FI valid'              => [ 'FI12345678', true ],
            'FI invalid'            => [ 'FI1234567', false ],
            'FR valid'              => [ 'FRXX123456789', true ],
            'FR invalid'            => [ 'FRXX12345678', false ],
            'GB valid'              => [ 'GB123456789', true ],
            'GB invalid'            => [ 'GB1234567890', false ],
            'HR valid'              => [ 'HR12345678901', true ],
            'HR invalid'            => [ 'HR1234567890', false ],
            'HU valid'              => [ 'HU12345678', true ],
            'HU invalid'            => [ 'HU123456789', false ],
            'IE valid'              => [ 'IE1234567WA', true ],
            'IE invalid'            => [ 'IE1234567890', false ],
            'IT valid'              => [ 'IT12345678901', true ],
            'IT invalid'            => [ 'IT1234567890', false ],
            'LT valid'              => [ 'LT123456789012', true ],
            'LT invalid'            => [ 'LT1234567890', false ],
            'LU valid'              => [ 'LU12345678', true ],
            'LU invalid'            => [ 'LU123456789', false ],
            'LV valid'              => [ 'LV12345678901', true ],
            'LV invalid'            => [ 'LV1234567890', false ],
            'MT valid'              => [ 'MT12345678', true ],
            'MT invalid'            => [ 'MT1234567', false ],
            'NL valid'              => [ 'NL123456789B01', true ],
            'NL invalid'            => [ 'NL123456789A01', false ],
            'PL valid'              => [ 'PL1234567890', true ],
            'PL invalid'            => [ 'PL123456789', false ],
            'PT valid'              => [ 'PT123456789', true ],
            'PT invalid'            => [ 'PT12345678', false ],
            'RO valid'              => [ 'RO12', true ],
            'RO invalid'            => [ 'RO1', false ],
            'SE valid'              => [ 'SE123456789012', true ],
            'SE invalid'            => [ 'SE12345678901', false ],
            'SI valid'              => [ 'SI12345678', true ],
            'SI invalid'            => [ 'SI1234567', false ],
            'SK valid'              => [ 'SK2020317057', true ],
            'SK invalid'            => [ 'SK202031705', false ],
            'SM valid'              => [ 'SM12345', true ],
            'SM invalid'            => [ 'SM1234', false ],
            'DK with spaces'                => [ 'DK12 34 56 78', true ],
            'lowercase'                     => [ 'sk2020317057', true ],
            'lowercase letters inside'      => [ 'nl123456789b01', true ],
            'leading space'                 => [ ' SK2020317057', false ],
            'inner space'                   => [ 'SK 2020317057', false ],
            'dots and dashes'               => [ 'SK-2020.317.057', false ],
            'unknown prefix'                => [ 'XX123456789', false ],
            'GR is not a VAT prefix'        => [ 'GR123456789', false ],
            'XI not known'                  => [ 'XI123456789', false ],
            'empty'                         => [ '', false ],
        ];
    }
}
