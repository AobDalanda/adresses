<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ConsumeOutboxCommand;
use App\Service\OutboxProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsumeOutboxCommandTest extends TestCase
{
    public function testJsonReportUsesConfiguredLimits(): void
    {
        $processor = $this->createMock(OutboxProcessor::class);
        $processor->expects(self::once())
            ->method('process')
            ->with(25, 4)
            ->willReturn(['processed' => 2, 'published' => 2, 'retried' => 0, 'failed' => 0]);

        $tester = new CommandTester(new ConsumeOutboxCommand($processor));
        $status = $tester->execute([
            '--limit' => '25',
            '--max-attempts' => '4',
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(2, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['published']);
    }
}
