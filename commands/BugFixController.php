<?php

/**
 * @copyright Copyright (C) 2015-2019 AIZAWA Hina
 * @license https://github.com/fetus-hina/stat.ink/blob/master/LICENSE MIT
 * @author AIZAWA Hina <hina@fetus.jp>
 */

declare(strict_types=1);

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\db\Expression;
use yii\db\Query;
use yii\helpers\Console;

class BugFixController extends Controller
{
    /**
     * battle2_splatnet.json カラムが不正に文字列になっているのを修正
     *
     * Yii 2.0.14 で JSON/JSONB 型サポートが追加されたことに伴い、
     * battle2_splatnet テーブルの json カラムに格納されるデータが、
     * 二重に JSON エンコードされたデータとなった。
     * Json::encode(Json::encode($data)); という形。
     * 内側は stat.ink のプログラムが、「どうせ文字列型でやりとりする」
     * と自発的にエンコードしていたもの（2.0.13以前ではこうしないと動かなかった）
     * 外側は ActiveRecord か Connection あたりが 2.0.14 から変換するもの。
     * この変な JSON をほどいてやることで、まともな JSONB カラムに変換する。
     * レコード数によるが死ぬほど時間がかかる。
     */
    public function actionBtl2SplatnetJson(): int
    {
        $db = Yii::$app->db;
        $indexName = 'tmp_ix_battle2_splatnet_' . hash('crc32b', __METHOD__);
        echo "Creating index ...\n";
        $db->createCommand(
            "CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS " .
            "[[{$indexName}]] ON {{battle2_splatnet}}([[id]]) " .
            "WHERE (JSONB_TYPEOF([[json]]) = 'string')"
        )
            ->execute();
        echo "Created\n";

        $countQuery = (new Query())
            ->select(['count' => 'COUNT(*)'])
            ->from('battle2_splatnet')
            ->andWhere(['JSONB_TYPEOF([[json]])' => 'string']);
        while (true) {
            $count = (int)$countQuery->scalar();
            if ($count < 1) {
                break;
            }
            echo date(DATE_ATOM, time()) . " {$count} rows remains ... ";

            $status = $db->transaction(function ($db): int {
                $idList = (new Query())
                    ->select(['id'])
                    ->from('battle2_splatnet')
                    ->andWhere(['JSONB_TYPEOF([[json]])' => 'string'])
                    ->orderBy(['id' => SORT_ASC])
                    ->limit(2000)
                    ->column();
                echo min($idList) . "-" . max($idList) . " ";
                $db->createCommand(
                    "UPDATE {{battle2_splatnet}} " .
                    "SET [[json]] = ([[json]]->>0)::JSONB " .
                    "WHERE (JSONB_TYPEOF([[json]]) = 'string') " .
                    "AND ([[id]] IN (" . implode(', ', array_map(
                        function ($id) use ($db): string {
                            return (string)$db->quoteValue($id);
                        },
                        $idList
                    )) . "))"
                )
                    ->execute();
                echo "commit ...\n";
                return 0;
            });
            if ($status != 0) {
                return $status;
            }
        }

        echo "Dropping index ...\n";
        $db->createCommand("DROP INDEX IF EXISTS {$indexName}")->execute();
        echo "Dropped\n";

        return 0;
    }
}
