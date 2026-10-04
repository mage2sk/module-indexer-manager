<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\IndexerManager\Model\DateFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateFormatterTest extends TestCase
{
    /**
     * @var array
     */
    private array $calls = [];

    private function formatter(?\Throwable $error = null): DateFormatter
    {
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('formatDateTime')->willReturnCallback(
            function ($date, $dateType, $timeType) use ($error) {
                if ($error !== null) {
                    throw $error;
                }
                $this->calls[] = [$date, $dateType, $timeType];
                $local = (clone $date)->setTimezone(new \DateTimeZone('America/Chicago'));
                return $local->format('M j, Y, g:i:s A');
            }
        );
        return new DateFormatter($timezone);
    }

    public function testGmtValueIsConvertedWithMediumDateAndTime(): void
    {
        $this->assertSame('Oct 4, 2026, 9:02:12 AM', $this->formatter()->format('2026-10-04 14:02:12'));
        $this->assertCount(1, $this->calls);
        [$date, $dateType, $timeType] = $this->calls[0];
        $this->assertInstanceOf(\DateTime::class, $date);
        $this->assertSame('UTC', $date->getTimezone()->getName());
        $this->assertSame('2026-10-04 14:02:12', $date->format('Y-m-d H:i:s'));
        $this->assertSame(\IntlDateFormatter::MEDIUM, $dateType);
        $this->assertSame(\IntlDateFormatter::MEDIUM, $timeType);
    }

    public static function emptyValues(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'spaces' => ['   '],
            'zero date' => ['0000-00-00 00:00:00'],
        ];
    }

    #[DataProvider('emptyValues')]
    public function testEmptyValuesGiveEmptyString(?string $value): void
    {
        $this->assertSame('', $this->formatter()->format($value));
        $this->assertSame([], $this->calls);
    }

    public function testUnparsableValueIsReturnedAsIs(): void
    {
        $this->assertSame('not a date', $this->formatter()->format('not a date'));
    }

    public function testFormatterErrorFallsBackToRawValue(): void
    {
        $formatter = $this->formatter(new \RuntimeException('intl missing'));
        $this->assertSame('2026-10-04 14:02:12', $formatter->format(' 2026-10-04 14:02:12 '));
    }
}
