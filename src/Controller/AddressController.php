<?php

namespace App\Controller;

use App\Repository\AddressRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/address')]
class AddressController extends AbstractController
{
    public function __construct(private AddressRepository $repo)
    {
    }

    #[Route('/lookup', methods: ['GET'])]
    public function lookup(Request $request): JsonResponse
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $latParam = $request->query->get('lat');
        $lngParam = $request->query->get('lng');
        if ($latParam === null || $latParam === '' || $lngParam === null || $lngParam === '') {
            return $this->json(['message' => 'lat et lng sont requis'], 400);
        }

        if (!is_numeric($latParam) || !is_numeric($lngParam)) {
            return $this->json(['message' => 'lat et lng doivent être numériques'], 400);
        }

        $lat = (float) $latParam;
        $lng = (float) $lngParam;
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return $this->json(['message' => 'lat ou lng hors limites'], 400);
        }

        $address = $this->repo->findNearest($lat, $lng);
        $area = $this->repo->findAdminArea($lat, $lng);

        if (!$address) {
            return $this->json(['message' => 'Adresse non trouvée'], 404);
        }

        return $this->json([
            'addressCode' => $address['address_code'],
            'distanceMeters' => round($address['distance'], 2),
            'adminArea' => $area,
        ]);
    }
}
