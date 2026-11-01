<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Mimic

`super-kernel/mimic` 把转换后的 PHP 源码准备为缓存的代理文件或只读的 `mimic://` 流。两个驱动都使用注入的 `Contract\MutatorInterface`。Mimic 负责源码快照和缓存发布；它不选择类、不注册自动加载器，也不执行生成的代码。`super-kernel/aop` 在它之上构建方法织入和拦截器调度。

## 环境要求

- 注入的 `SuperKernel\Contract\VendorDirInterface`，用于确定缓存位置。
- 两个驱动都需要注入的 `SuperKernel\Mimic\Contract\MutatorInterface`。
- 文件驱动需要注入的 `SuperKernel\Contract\PackageManagerInterface`，用于取得源码的类名。
- 本地的、可读的、可信的 PHP 源文件。

Mimic 在运行时不依赖 PHP-Parser 或 Swoole。传入的转换可以声明自己的解析器依赖。流缓冲属于当前进程。

## 驱动与缓存生命周期

`MimicFactory` 通过构造函数接收 `Driver\FileDriver` 和 `Driver\StreamDriver`。`prepare($sourcePath)` 读取一份源码快照，返回代理文件路径或 `mimic://` URI。默认策略先尝试 File；当缓存无法创建、读取、写入或原子替换，或者源码不在包管理器的类图中时，改用 Stream。

| 驱动           | 结果               | 存储位置                             |
|----------------|--------------------|--------------------------------------|
| `FileDriver`   | 代理文件的绝对路径 | `<VendorDirInterface>/.cache/mimic/` |
| `StreamDriver` | `mimic://` URI     | 当前进程内存                         |

代理文件直接位于 `<VendorDirInterface>/.cache/mimic/<class-name>.tpl`。文件名是原始的完全限定类名，命名空间分隔符替换为点，例如 `Src.Service.TestService.tpl`，因此 `Acme\Service\Test` 和 `Acme\Service_Test` 不会混淆。类名由包管理器提供，无需反射或加载源码。一个文件声明了多个已映射的符号时，用其排序后类图中的第一个名称命名代理。新发布的代理和已缓存的代理返回同一个地址。

第一行是 `<?php // mimic-cache-sha256: <digest>`。摘要是对规范的源码路径、mutator 的缓存键和源码内容哈希（以空字节分隔）计算的 SHA-256；原始路径不会写进代理文件。指纹匹配时直接复用可读的代理，不写入、不创建目录，也不需要 Stream 协议。源码内容变化、源码位置移动或 mutator 键变化，都会替换同一个以类名命名的代理。从不使用修改时间、inode 或文件大小来检测变化。每个类在一个 vendor 缓存中只有一个文件位置，因此共享该缓存的并发集成必须使用相同的源码位置和转换。需要为不同转换保留各自独立的快照时，请使用 Stream。

完整的临时文件在缓存目录内原子重命名，不使用锁文件。并发的发布者可能重复执行转换，但读取方永远不会看到写了一半的代理。已准备的文件路径会跟随之后的替换；需要保留不同快照时，请使用 Stream 驱动的不可变地址。替换成功后，如果 OPcache 可用，会使其失效。发布失败时保留已有的代理，并通过 Stream 准备当前快照。文件头无法读取或无法识别时，同样改用 Stream，不会覆盖这个无法验证的代理。解析和转换失败会带着原始 Throwable 向外传播。准备过程从不读取、重命名或删除其他缓存文件，例如哈希子目录、`.php` 或 `.php.tpl` 代理以及 `.lock` 文件。

Stream 驱动延迟注册其协议，并拒绝冲突的协议持有者。其 URI 包含源码路径、内容哈希和 mutator 缓存键，因此为另一个源码版本或另一种转换准备快照时，不会改变已有的快照。

两个驱动都实现 `Contract\MimicDriverInterface::prepare(SourceFile)`。`Source\SourceFile` 记录规范路径、源码字节和内容哈希。缓存不可用时 File 返回 `null`；Stream 返回 URI 或抛出异常。

已加载到运行中进程的类无法被替换：要加载新生成的代码，请重启工作进程。持久化的类声明发生变化时，请重新生成注解缓存以及仅恢复或 Phar 的产物。

## 转换契约

`Contract\MutatorInterface` 定义 `mutate($code, $sourcePath)` 和 `getCacheKey()`。转换结果必须是以 `<?php` 开头的完整 PHP 源码。缓存键必须标识转换的版本以及所有影响其输出的配置，在等价的实例之间保持稳定，并在相同源码需要生成不同代码时发生变化。两个驱动必须接收同一个转换。

安装了 `super-kernel/aop` 时，它的 `SuperKernel\Aop\Mutation\Mutator` 被绑定到 `MutatorInterface`，AOP 的加载器通过 Mimic 准备并加载收集到的 Aspect 类。支持的语义参见 [AOP README](https://github.com/super-kernel/aop/blob/main/README.md)。

`Diagnostics\ExceptionPatcher` 是一个通用工具。它的 `patch($throwable, $originalFile, $originalLine)` 为从生成代码中抛出的异常设置原始位置。Mimic 从不替换全局异常处理器。

## 上游

本组件移植自 [wheakerd/mimic](https://github.com/wheakerd/mimic) 的 [443ad3e4a2c8dd9ce7b6028b0b7aea163be4e47d](https://github.com/wheakerd/mimic/tree/443ad3e4a2c8dd9ce7b6028b0b7aea163be4e47d)。该仓库属于 wheakerd，wheakerd 已明确授权将其源码导入并改编到 `super-kernel/mimic`。wheakerd 为本组件指定了 [MIT 许可证](LICENSE)。这份来源和授权声明保留在 [`NOTICE`](NOTICE) 中，分发的归档里也包含它。

| 身份         | 上游                 | 本组件               |
|--------------|----------------------|----------------------|
| Composer 包  | `wheakerd/php-mimic` | `super-kernel/mimic` |
| PHP 命名空间 | `PhpMimic\`          | `SuperKernel\Mimic\` |
