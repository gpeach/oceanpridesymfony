<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\GlobalsInterface;

class AppExtension extends AbstractExtension implements GlobalsInterface
{
    private RequestStack $requestStack;
    private array $globals;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
        $this->globals = [
            'corp_name' => 'Ocean Pride Media',
            'email' => 'gary@oceanpridemedia.com',
            'phone' => '(754) 366-0563',
            'address' => '1541 South Ocean Blvd #411, Pompano Beach, FL, USA',
            'my_name' => 'Gary Hardin-Peach',
            'my_first_name' => 'Gary',
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('basename', 'basename'),
        ];
    }

    public function getGlobals(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $uri = $request ? $request->getRequestUri() : '';

        return array_merge($this->globals, [
            'app' => ['request' => ['uri' => $uri]],
        ]);
    }
}
