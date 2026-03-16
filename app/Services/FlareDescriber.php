<?php

namespace App\Services;

use App\Concerns\RendersBanner;
use Illuminate\Console\Application;
use NunoMaduro\LaravelConsoleSummary\Contracts\DescriberContract;
use NunoMaduro\LaravelConsoleSummary\Describer;
use Symfony\Component\Console\Output\OutputInterface;

class FlareDescriber extends Describer
{
    use RendersBanner;

    protected function describeTitle(Application $application, OutputInterface $output): DescriberContract
    {
        $this->renderBanner($output);

        return parent::describeTitle($application, $output);
    }

    protected function describeUsage(OutputInterface $output): DescriberContract
    {
        parent::describeUsage($output);

        $output->writeln([
            '',
            '  <fg=yellow;options=bold>OUTPUT:</>  <fg=green>--json</>              Raw JSON response',
            '           <fg=green>--yaml</>              YAML response',
            '           <fg=green>--minify</>            Compact output, no pretty-printing',
            '           <fg=green>-H, --headers</>       Include response headers',
            '           <fg=green>--output-html</>       Show full HTML response body',
            '',
            '  <fg=yellow;options=bold>INPUT:</>   <fg=green>--field key=value</>   Send form fields (repeatable, file: --field key=@path)',
            '           <fg=green>--input \'{}\'</>        Send raw JSON body',
            '',
            '  <fg=yellow;options=bold>GLOBAL:</>  <fg=green>-V, --version</>       Show version',
            '           <fg=green>-q, --quiet</>         Suppress all output',
            '           <fg=green>-v, --verbose</>       Increase verbosity (-vv, -vvv for more)',
            '           <fg=green>-n, --no-interaction</>  Skip interactive prompts',
            '           <fg=green>--no-ansi</>           Disable color output',
            '',
            '  <fg=yellow;options=bold>HELP:</>    <fg=green>help <command></>      Show all options for a command',
            '           <fg=green><command> --help</>     Same as above',
        ]);

        return $this;
    }
}
