<?php

declare(strict_types=1);

namespace PhpSolidity\Tests\Unit;

use PhpSolidity\Config\Config;
use PhpSolidity\Exceptions\PhpSolidityException;
use PhpSolidity\Tests\TestCase;
use PhpSolidity\TypeSystem\Naming;

final class NamingAndConfigTest extends TestCase
{
    public function testReservedWordsAndStateNamesGetASuffix(): void
    {
        $this->assertSame('default_', Naming::safe('default'));
        $this->assertSame('owner_', Naming::safe('owner', ['owner']));
        $this->assertSame('amount', Naming::safe('amount', ['owner']));
        $this->assertTrue(Naming::isReserved('Memory'));
    }

    public function testConfigLoadsAndAppliesDefaults(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cfg');
        $this->assertNotFalse($file);
        file_put_contents($file, '{"input": "contracts", "output": "build"}');

        $config = Config::load($file);
        unlink($file);

        $this->assertSame(['contracts'], $config->input);
        $this->assertSame('build', $config->output);
        $this->assertTrue($config->optimize);
        $this->assertSame(200, $config->runs);
    }

    public function testBrokenConfigIsRejected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cfg');
        $this->assertNotFalse($file);
        file_put_contents($file, '{nope');

        try {
            $this->expectException(PhpSolidityException::class);
            Config::load($file);
        } finally {
            unlink($file);
        }
    }

    public function testTemplateIsValidJson(): void
    {
        $this->assertIsArray(json_decode(Config::template(), true));
    }
}
