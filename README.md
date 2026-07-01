# sapl-php

Policy-based authorization for PHP and Symfony. Write access control rules as external SAPL policy files and enforce them at runtime through attributes like `#[PreEnforce]` and `#[PostEnforce]`. Policies can be updated without code changes or redeployment.

## How It Works

Your application marks controller actions or service methods with enforcement attributes. SAPL intercepts the call, sends an authorization subscription to the Policy Decision Point (PDP), and enforces the decision, including any obligations or advice the policy attaches.

```php
use Sapl\Symfony\PreEnforce;

#[PreEnforce(action: 'read', resource: 'patient')]
public function getPatient(): array
{
    return ['name' => 'Jane Doe', 'ssn' => '123-45-6789'];
}
```

```
policy "permit doctors to read patient data"
permit
  action == "read";
  "DOCTOR" in subject.roles
```

If the PDP permits, the method runs. If not, an `AccessDeniedException` is thrown. If the decision carries obligations (like access logging or field redaction), they are enforced automatically through registered constraint handlers.

The subject defaults to the authenticated user, and the action and resource to the class and method name. Any attribute field overrides the default, either as a literal or as a Symfony `Expression` evaluated against `{ subject, args, request }`.

## What You Get

SAPL goes beyond simple permit/deny. Decisions can carry obligations that must be fulfilled, advice that should be attempted, and resource transformations that modify return values before they reach the caller. The library handles all of this transparently.

For streaming responses, `#[StreamEnforce]` maintains a live connection to the PDP, so access rights update in real time as policies, attributes, or the environment change. Its `signalTransitions` and `pauseRapDuringSuspend` flags express the suspend, drop, and pause behaviours. Transaction integration rolls back a database write when an obligation fails after it (enable with `transactional: true`).

Data-layer query rewriting narrows results at the database rather than in memory. A policy attaches a `sql:queryRewriting` or `mongo:queryRewriting` obligation, and the matching Doctrine filter rewrites the queries an enforced method issues, fail-closed and narrowing-only. `Sapl\Doctrine\Orm\SaplSqlFilter` covers SQL databases through the Doctrine ORM and `Sapl\Doctrine\Odm\SaplBsonFilter` covers MongoDB through the Doctrine ODM. The obligation is portable: the same `mongo:queryRewriting` policy works unchanged across the Spring, Python, NestJS, and PHP MongoDB integrations.

## Getting Started

Requires PHP 8.3+, Symfony 7.3+, and a SAPL PDP of version 4.1.0 or higher.

```
composer require sapl/sapl-php
```

Register the bundle and point it at your PDP:

```yaml
# config/packages/sapl.yaml
sapl:
    pdp:
        base_url: '%env(SAPL_PDP_URL)%'
    transactional: false
```

The PDP connection is HTTP. An unauthenticated development PDP must stay on loopback; production deployments configure authentication (api-key via `token`, or basic auth via `username` and `secret`) and TLS.

## Links

- [Full Documentation](https://sapl.io/docs/latest/)
- [Demo Application](https://github.com/heutelbeck/sapl-php-demos)
- [Report an Issue](https://github.com/heutelbeck/sapl-php/issues)

## License

Apache-2.0
