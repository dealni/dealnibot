<?php

declare(strict_types=1);

namespace Tests\Support;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/** Normaliza somente a API declarada; não instancia o cliente nem chama endpoints. */
final class ContratoApiPublica
{
    /** @template T of object
     * @param ReflectionClass<T> $classe
     * @return array<string, string>
     */
    public static function extrair(ReflectionClass $classe): array
    {
        $assinaturas = [];
        foreach ($classe->getMethods(ReflectionMethod::IS_PUBLIC) as $metodo) {
            $parametros = array_map(self::parametro(...), $metodo->getParameters());
            $assinaturas[$metodo->getName()] = ($metodo->isStatic() ? 'static ' : '')
                . ($metodo->returnsReference() ? '&' : '') . $metodo->getName()
                . '(' . implode(', ', $parametros) . '): ' . self::tipo($metodo->getReturnType());
        }
        ksort($assinaturas);

        return $assinaturas;
    }

    private static function parametro(ReflectionParameter $parametro): string
    {
        $assinatura = self::tipo($parametro->getType()) . ' '
            . ($parametro->isPassedByReference() ? '&' : '')
            . ($parametro->isVariadic() ? '...' : '') . '$' . $parametro->getName();
        if ($parametro->isDefaultValueAvailable()) {
            $assinatura .= ' = ' . json_encode($parametro->getDefaultValue(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if ($parametro->isDefaultValueConstant()) {
                $assinatura .= ' [' . $parametro->getDefaultValueConstantName() . ']';
            }
        }

        return $assinatura;
    }

    private static function tipo(?ReflectionType $tipo): string
    {
        if ($tipo === null) {
            return 'sem-tipo';
        }
        if ($tipo instanceof ReflectionNamedType) {
            $nome = $tipo->getName();

            return $tipo->allowsNull() && !in_array($nome, ['null', 'mixed'], true) ? $nome . '|null' : $nome;
        }
        if ($tipo instanceof ReflectionUnionType || $tipo instanceof ReflectionIntersectionType) {
            $partes = array_map(static fn (ReflectionType $parte): string => $parte instanceof ReflectionIntersectionType
                ? '(' . self::tipo($parte) . ')' : self::tipo($parte), $tipo->getTypes());
            sort($partes);

            return implode($tipo instanceof ReflectionUnionType ? '|' : '&', $partes);
        }

        throw new \LogicException('Tipo de reflexão não suportado.');
    }
}
