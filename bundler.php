<?php
require 'vendor/autoload.php';

use PhpParser\{Node, NodeTraverser, NodeVisitorAbstract, ParserFactory, PrettyPrinter};
use PhpParser\NodeFinder;

// PHPパーサーを作成
$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
$prettyPrinter = new PrettyPrinter\Standard();
$traverser = new NodeTraverser();
$nodeFinder = new NodeFinder();

// **1. ソースコードのファイル一覧**
$files = glob('src/*.php'); // srcディレクトリのPHPファイルをすべて取得
$astList = [];
$codeMap = [];

// **2. ファイルをASTに変換**
foreach ($files as $file) {
    $code = file_get_contents($file);
    $ast = $parser->parse($code);
    $astList[$file] = $ast;
    $codeMap[$file] = $code;
}

// **3. 使用されている関数・クラス・メソッドを収集**
$usedSymbols = [];

foreach ($astList as $ast) {
    $calls = $nodeFinder->findInstanceOf($ast, Node\Expr\FuncCall);
    $methods = $nodeFinder->findInstanceOf($ast, Node\Expr\MethodCall);
    $staticMethods = $nodeFinder->findInstanceOf($ast, Node\Expr\StaticCall);
    $newObjects = $nodeFinder->findInstanceOf($ast, Node\Expr\New_);

    foreach ($calls as $call) {
        if ($call->name instanceof Node\Name) {
            $usedSymbols[$call->name->toString()] = true;
        }
    }
    foreach ($methods as $method) {
        if ($method->name instanceof Node\Identifier) {
            $usedSymbols[$method->name->toString()] = true;
        }
    }
    foreach ($staticMethods as $staticMethod) {
        if ($staticMethod->name instanceof Node\Identifier) {
            $usedSymbols[$staticMethod->name->toString()] = true;
        }
    }
    foreach ($newObjects as $newObject) {
        if ($newObject->class instanceof Node\Name) {
            $usedSymbols[$newObject->class->toString()] = true;
        }
    }
}

// **4. 未使用の関数・クラス・メソッドを削除**
class UnusedCodeRemover extends NodeVisitorAbstract {
    private array $usedSymbols;

    public function __construct(array $usedSymbols) {
        $this->usedSymbols = $usedSymbols;
    }

    public function leaveNode(Node $node) {
        // 未使用関数を削除
        if ($node instanceof Node\Stmt\Function_ && !isset($this->usedSymbols[$node->name->toString()])) {
            return NodeTraverser::REMOVE_NODE;
        }
        // 未使用クラスを削除
        if ($node instanceof Node\Stmt\Class_ && !isset($this->usedSymbols[$node->name->toString()])) {
            return NodeTraverser::REMOVE_NODE;
        }
        // 未使用メソッドを削除（クラスが使われている場合でも不要なメソッドを削除）
        if ($node instanceof Node\Stmt\ClassMethod && !isset($this->usedSymbols[$node->name->toString()])) {
            return NodeTraverser::REMOVE_NODE;
        }
    }
}

// 削除処理を適用
$traverser->addVisitor(new UnusedCodeRemover($usedSymbols));

$mergedAst = [];
foreach ($astList as $ast) {
    $mergedAst = array_merge($mergedAst, $traverser->traverse($ast));
}

// **5. ASTからPHPコードに変換し、バンドル**
$bundledCode = "<?php\n" . $prettyPrinter->prettyPrint($mergedAst);
file_put_contents('index.php', $bundledCode);

echo "バンドル完了: index.php\n";
