<?php

namespace isaactorresmichel\cfdi2csv\Commands;

use CFDIReader\CFDIReader;
use League\Csv\Writer;
use RecursiveDirectoryIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\PathUtil\Path;

class CompileCommand extends Command {

  /** @var \Symfony\Component\Filesystem\Filesystem */
  protected $fs;
  /** @var  CFDIReader[] */
  protected $comprobantes;

  public function __construct() {
    parent::__construct();
    $this->fs = new Filesystem();
  }

  protected function configure() {
    $this->setName('cfdi2csv')
      ->setDescription('Process los XML de la carpeta indicada generando un archivo CSV.')
      ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Directorio a procesar.')
      ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Nombre de archivo de salida');
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $output->writeln($this->getApplication()->getLongVersion());

    $dir = $input->getOption('dir') ?? getcwd();
    $dir = $this->normalizePath($dir);
    if (!$this->fs->exists($dir)) {
      throw new \Exception('Directorio a examinar inválido');
    }

    $output->writeln("<comment>Procesando directorio: {$dir}</comment>");

    if ($file_name = $input->getOption('file')) {
      $this->fileIsWritable($file_name);
    }
    else {
      $dirname = explode('/', Path::normalize(getcwd()));
      $file_name = getcwd() . DIRECTORY_SEPARATOR . end($dirname) . '-' . time() . '.csv';
      $file_name = $this->normalizePath($file_name);
    }

    try {
      $this->fs->touch($file_name);
    } catch (IOException $exception) {
      throw new IOException("Fallo al crear archivo: {$file_name}. Error: {$exception->getMessage()}");
    }

    $output->writeln("<comment>Generando archivo de salida: {$file_name}</comment>");

    foreach ($this->getFiles($dir) as $file) {
      $content = file_get_contents($file->getPathname());
      $this->comprobantes[] = new CFDIReader($content);
      $output->writeln("Se ha añadido: " . $file->getFilename());
    }

    usort($this->comprobantes, function (CFDIReader $a, CFDIReader $b) {
      if ($a->attribute('fecha') > $b->attribute('fecha')) {
        return 1;
      }
      elseif ($a->attribute('fecha') < $b->attribute('fecha')) {
        return -1;
      }
      return 0;
    });
    $output->writeln("<comment>Se ha ordenado la lista de comprobantes.</comment>");

    $csv = Writer::createFromPath($file_name);
    foreach ($this->comprobantes as $comprobante) {
      $output->writeln("Añadiendo comprobante {$comprobante->getUUID()} a CSV.");
      $data = [
        $comprobante->attribute('complemento','timbreFiscalDigital', 'UUID'),
        $comprobante->attribute('fecha'),
        $comprobante->attribute('emisor', 'rfc'),
        $comprobante->attribute('emisor', 'nombre'),
        $comprobante->attribute('receptor', 'rfc'),
        $comprobante->attribute('receptor', 'nombre'),
        $comprobante->attribute('moneda'),
        $comprobante->attribute('total'),
        $comprobante->attribute('impuestos', 'totalImpuestosTrasladados'),
      ];

      $traslados = $comprobante->node('impuestos','traslados');
      if($traslados){
        $traslados = $traslados->xpath('//traslado');
        foreach ($traslados as $traslado){
          $data[] = "{$traslado['impuesto']}|{$traslado['tasa']}%|{$traslado['importe']}";
        }
      }

      $csv->insertOne($data);
    }
  }

  /**
   * @param $path
   * @return \SplFileInfo[]
   */
  protected function getFiles($path) {
    $dirIterator = new RecursiveDirectoryIterator($path,
      RecursiveDirectoryIterator::FOLLOW_SYMLINKS
      | RecursiveDirectoryIterator::SKIP_DOTS);

    $items = [];
    /** @var \SplFileInfo $item */
    foreach (new \RecursiveIteratorIterator($dirIterator) as $item) {
      if ($item->getExtension() === "xml") {
        $items[] = $item;
      }
    }
    return $items;
  }

  protected function fileIsWritable($file_name) {
    if ($this->fs->exists($file_name)) {
      throw new \Exception("El archivo a generar: \"{$file_name}\" ya existe.");
    }

    try {
      $file_name = $this->normalizePath($file_name);
      $directory = dirname($file_name);
      if (!$this->fs->exists($directory)) {
        $this->fs->mkdir($directory, 0755);
        return;
      }

      if (!is_writable($file_name)) {
        $this->fs->chmod($directory, 0755);
      }
    } catch (IOException $exception) {
      throw new IOException("Fallo al crear archivo: {$file_name}. Error: {$exception->getMessage()}");
    }
  }

  protected function normalizePath($path) {
    $path = Path::normalize($path);
    if (Path::isAbsolute($path)) {
      return $path;
    }
    return Path::makeAbsolute($path, getcwd());
  }
}