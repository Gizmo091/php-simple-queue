# php-simple-queue


## Usage
```php 
$Queue = new PhpSimpleQueue\FileQueue( 'dexio', true, 5, 1000 );
$Queue->enterInQueue( 20000, function() use () {
	// Job to do
} );
```