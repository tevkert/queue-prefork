<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\StreamOutput;
use Tevkert\QueuePrefork\Support\LineBufferFilter;
use Tevkert\QueuePrefork\Support\LineBufferedOutput;

class LineBufferedOutputTest extends TestCase
{
    public function test_a_line_written_in_pieces_arrives_whole(): void
    {
        [$writer, $reader] = $this->filteredPair();

        fwrite($writer, '  2026-11-09 06:05:32 Laravel\Scout\Jobs\MakeSearchable');
        fwrite($writer, ' .........');
        $this->assertSame('', $this->readAvailable($reader));

        fwrite($writer, " RUNNING\n");
        $this->assertSame("  2026-11-09 06:05:32 Laravel\\Scout\\Jobs\\MakeSearchable ......... RUNNING\n", $this->readAvailable($reader));
    }

    public function test_complete_lines_pass_and_the_rest_waits(): void
    {
        [$writer, $reader] = $this->filteredPair();

        fwrite($writer, "one\ntwo\nthr");
        $this->assertSame("one\ntwo\n", $this->readAvailable($reader));

        fwrite($writer, "ee\n");
        $this->assertSame("three\n", $this->readAvailable($reader));
    }

    public function test_a_partial_line_is_passed_on_when_the_stream_closes(): void
    {
        [$writer, $reader] = $this->filteredPair();

        fwrite($writer, 'no newline');
        fclose($writer);

        $this->assertSame('no newline', $this->readAvailable($reader));
    }

    public function test_a_partial_line_is_not_held_past_the_limit(): void
    {
        [$writer, $reader] = $this->filteredPair();

        fwrite($writer, str_repeat('x', LineBufferFilter::MAX_HELD));

        $this->assertSame(LineBufferFilter::MAX_HELD, strlen($this->readAvailable($reader)));
    }

    public function test_a_console_output_on_a_filtered_stream_writes_whole_lines_and_attaching_twice_is_harmless(): void
    {
        [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        LineBufferedOutput::attachTo($writer);
        LineBufferedOutput::attachTo($writer);
        $output = new StreamOutput($writer);

        $output->write('half');
        $this->assertSame('', $this->readAvailable($reader));

        $output->writeln(' and half');
        $this->assertSame("half and half\n", $this->readAvailable($reader));
    }

    /** @return array{0: resource, 1: resource} */
    private function filteredPair(): array
    {
        [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        LineBufferedOutput::attachTo($writer);

        return [$writer, $reader];
    }

    /** @param resource $reader */
    private function readAvailable($reader): string
    {
        stream_set_blocking($reader, false);
        usleep(20_000);
        $data = '';

        while (($chunk = fread($reader, 65536)) !== false && $chunk !== '') {
            $data .= $chunk;
        }

        return $data;
    }
}
