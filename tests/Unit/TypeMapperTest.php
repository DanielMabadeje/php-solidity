<?php

declare(strict_types=1);

namespace PhpSolidity\Tests\Unit;

use PhpSolidity\Exceptions\UnsupportedTypeException;
use PhpSolidity\Tests\TestCase;
use PhpSolidity\TypeSystem\TypeMapper;
use PHPUnit\Framework\Attributes\DataProvider;

final class TypeMapperTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function mappings(): array
    {
        return [
            'uint256'       => ['uint256', 'uint256'],
            'uint24 (every step of 8)' => ['uint24', 'uint24'],
            'int160'        => ['int160', 'int160'],
            'native int'    => ['int', 'int256'],
            'bool'          => ['bool', 'bool'],
            'address'       => ['address', 'address'],
            'string'        => ['string', 'string'],
            'bytes'         => ['bytes', 'bytes'],
            'bytes1'        => ['bytes1', 'bytes1'],
            'bytes32'       => ['bytes32', 'bytes32'],
            'void'          => ['void', ''],
            'struct name'   => ['Point', 'Point'],
            'dynamic array' => ['uint256[]', 'uint256[]'],
            'fixed array'   => ['address[3]', 'address[3]'],
            'leading slash' => ['\\uint256', 'uint256'],
        ];
    }

    #[DataProvider('mappings')]
    public function testMapsTypes(string $php, string $expected): void
    {
        $this->assertSame($expected, (new TypeMapper())->toSolidity($php));
    }

    /** @return array<string, array{string}> */
    public static function rejected(): array
    {
        return [
            ['float'], ['double'], ['array'], ['object'], ['mixed'], ['null'],
            ['callable'], ['iterable'], ['uint7'], ['uint264'], ['bytes33'], ['bytes0'], ['lowercase_unknown'],
        ];
    }

    #[DataProvider('rejected')]
    public function testRejectsUnsupportedTypes(string $php): void
    {
        $this->expectException(UnsupportedTypeException::class);
        (new TypeMapper())->toSolidity($php);
    }

    public function testBlockedTypesExplainWhatToDoInstead(): void
    {
        $this->expectExceptionMessage('fixed-point');
        (new TypeMapper())->toSolidity('float');
    }

    public function testDataLocationOnlyForReferenceTypes(): void
    {
        $types = new TypeMapper();

        $this->assertTrue($types->needsDataLocation('string'));
        $this->assertTrue($types->needsDataLocation('bytes'));
        $this->assertTrue($types->needsDataLocation('uint256[]'));
        $this->assertTrue($types->needsDataLocation('Point', ['Point']));
        $this->assertFalse($types->needsDataLocation('Point'));          // unknown name: assume contract/interface
        $this->assertFalse($types->needsDataLocation('uint256'));
        $this->assertFalse($types->needsDataLocation('bytes32'));
        $this->assertFalse($types->needsDataLocation('address'));
    }

    public function testSplitsNestedMappings(): void
    {
        $types = new TypeMapper();

        $this->assertSame(
            ['key' => 'address', 'value' => 'mapping(address => uint256)'],
            $types->splitMapping('mapping(address => mapping(address => uint256))'),
        );
        $this->assertSame('uint256', $types->mappingValueType('mapping(address => uint256)'));
        $this->assertNull($types->mappingValueType('uint256'));
        $this->assertSame('uint256', $types->arrayElementType('uint256[]'));
        $this->assertSame('address', $types->arrayElementType('address[3]'));
        $this->assertNull($types->arrayElementType('address'));
    }
}
