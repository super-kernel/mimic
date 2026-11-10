<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Mimic

`super-kernel/mimic` 通过你提供的转换，把 PHP 源文件的一份转换后的副本准备为缓存的代理文件或只读的 `mimic://` 流，然后由你的代码加载它。

## 安装

```bash
composer require super-kernel/mimic
```

本包除 PHP 外没有其他要求，也不依赖 PHP-Parser 或 Swoole。它需要一个 `SuperKernel\Mimic\Contract\MutatorInterface`：`super-kernel/aop` 提供了一个，否则由应用声明自己的。依赖目录（vendor 目录）和包的位置来自 `skernel build` 的元数据，因此项目需要用 SKERNEL 构建。

## 提供的能力

- `MimicFactory::prepare()`，返回某个文件转换后源码的地址。
- 位于 `vendor/.cache/mimic/` 中、以类命名的代理文件，在源码和转换保持不变时会被重用。
- 在项目移动或被打包进 Phar 归档之后仍然有效的代理。
- 当缓存无法使用或文件位于项目的包之外时，内存中的 `mimic://` 流。
- 原子发布，使并发的进程永远不会读到不完整的代理。

它不会：

- 选择要转换哪些类，或注册自动加载器；
- 执行它准备的源码；
- 替换进程已经加载的类；
- 自己解析 PHP，这由转换负责。

## 快速开始

在没有 `super-kernel/aop` 的项目中，声明一个带有占位符的类。将它保存为 `src/Billing/Edition.php`：

```php
<?php

declare(strict_types=1);

namespace Src\Billing;

final class Edition
{
    public const string NAME = '%EDITION%';
}
```

声明转换。将它保存为 `src/Mimic/FillEdition.php`：

```php
<?php

declare(strict_types=1);

namespace Src\Mimic;

use SuperKernel\Attribute\Provider;
use SuperKernel\Mimic\Contract\MutatorInterface;

use function str_replace;

#[Provider(MutatorInterface::class)]
final readonly class FillEdition implements MutatorInterface
{
    public function getCacheKey(): string
    {
        return 'fill-edition-1';
    }

    public function mutate(string $code, ?string $sourcePath = null): string
    {
        return str_replace('%EDITION%', 'Community', $code);
    }
}
```

准备并加载该类。将脚本保存为 `bin/edition.php`：

```php
<?php

declare(strict_types=1);

use Src\Billing\Edition;
use SuperKernel\Context\ApplicationContext;
use SuperKernel\Contract\BasePathInterface;
use SuperKernel\Mimic\MimicFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$container = ApplicationContext::getContainer();
$source = $container->get(BasePathInterface::class) . '/src/Billing/Edition.php';

$address = $container->get(MimicFactory::class)->prepare($source);

require $address;

echo basename($address), PHP_EOL;
echo Edition::NAME, PHP_EOL;
```

构建项目并运行脚本：

```bash
skernel build
php bin/edition.php
```

它会打印：

```text
Src.Billing.Edition.tpl
Community
```

## 公开接口

| 名称 | 类别 | 用途 |
| --- | --- | --- |
| `SuperKernel\Mimic\Contract\MutatorInterface` | 接口 | 由转换实现：`mutate()` 返回新的源码，`getCacheKey()` 标识该转换。 |
| `SuperKernel\Mimic\MimicFactory` | 类 | 由容器提供；`prepare()` 返回代理文件路径或 `mimic://` 地址。 |
| `SuperKernel\Mimic\Contract\MimicDriverInterface` | 接口 | 由两个驱动实现；`prepare()` 接受一个源码快照。 |
| `SuperKernel\Mimic\Driver\FileDriver` | 类 | 在依赖目录的缓存中写入并重用代理文件。 |
| `SuperKernel\Mimic\Driver\StreamDriver` | 类 | 把转换后的源码保存在内存中，通过 `mimic://` 地址访问。 |
| `SuperKernel\Mimic\Source\SourceFile` | 类 | 一个源文件的快照：其路径、内容和内容哈希。 |
| `SuperKernel\Mimic\Diagnostics\ExceptionPatcher` | 类 | `patch()` 为来自生成代码的异常设置其原始文件和行号。 |

## 需要了解的行为

- 只要源码内容、它相对于包的位置以及缓存键保持不变，代理就会被重用；从不使用修改时间。每当相同的源码必须生成不同的代码时，请修改 `getCacheKey()`。
- 当缓存无法创建、读取或写入，或者源码位于项目的包之外时，`prepare()` 改为返回 `mimic://` 地址，已有的代理保持不变。
- 无法读取的源文件会抛出 `InvalidArgumentException`，消息为 `The source file is not readable: <path>`；转换抛出的异常原样向外传播。
- 如果另一个库占用了 `mimic` 流协议，回退会以 `The mimic stream protocol is already registered by another library.` 失败。
- 进程已经加载的类无法被替换：修改之后请重启长时间运行的工作进程，并在修改类或包之后重新运行 `skernel build`。

## 文档

开发者文档介绍了驱动、缓存及其指纹、流回退、如何编写转换以及诊断工具，并附有完整示例：[SuperKernel Mimic](https://github.com/super-kernel/.github/blob/main/docs/components/mimic.zh-CN.md)。

## 许可证

基于 MIT 许可证发布，详见 [LICENSE](LICENSE)。上游源码及其授权记录在 [NOTICE](NOTICE) 中。
