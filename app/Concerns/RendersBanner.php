<?php

namespace App\Concerns;

use Symfony\Component\Console\Output\OutputInterface;

trait RendersBanner
{
    public function renderBanner(OutputInterface $output): void
    {
        $lines = [
            '  ███████╗ ██╗       █████╗  ██████╗  ███████╗',
            '  ██╔════╝ ██║      ██╔══██╗ ██╔══██╗ ██╔════╝',
            '  █████╗   ██║      ███████║ ██████╔╝ █████╗  ',
            '  ██╔══╝   ██║      ██╔══██║ ██╔══██╗ ██╔══╝  ',
            '  ██║      ███████╗ ██║  ██║ ██║  ██║ ███████╗',
            '  ╚═╝      ╚══════╝ ╚═╝  ╚═╝ ╚═╝  ╚═╝ ╚══════╝',
        ];

        $gradient = [49, 43, 37, 99, 135, 93];

        $output->writeln('');

        foreach ($lines as $i => $line) {
            $output->writeln("\e[38;5;{$gradient[$i]}m{$line}\e[0m");
        }

        $output->writeln('');

        $tagline = ' ✦ Catch errors. Fix slowdowns. :: flareapp.io ✦ ';
        $output->writeln("\e[48;5;{$gradient[0]}m\e[30m\e[1m{$tagline}\e[0m");

        $output->writeln('');
    }
}
