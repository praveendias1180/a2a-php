# API reference

The full class reference is generated from the source code with phpDocumentor on every docs build:

**[Open the API reference →](../../api/index.html)**

It covers the public API of both packages (`praveendias1180/a2a-php` and `praveendias1180/a2a-laravel`) and the generated wire types in `A2A\Types`. Classes marked `@internal` are left out, because they aren't covered by the [backward-compatibility promise](backward-compatibility.md).

The class names and their place in the namespace tree follow the official Python SDK; see the [Python → PHP mapping](../python-sdk-mapping.md).

To build it locally:

```bash
curl -sSfL -o phpDocumentor.phar https://github.com/phpDocumentor/phpDocumentor/releases/download/v3.10.0/phpDocumentor.phar
zensical build --clean && php phpDocumentor.phar run -c phpdoc.dist.xml
# open site/api/index.html
```
