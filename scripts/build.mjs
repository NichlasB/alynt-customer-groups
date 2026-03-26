import { existsSync } from 'node:fs';
import * as esbuild from 'esbuild';

const isWatch = process.argv.includes('--watch');
const entryPoints = [
  'assets/src/admin/index.js',
  'assets/src/frontend/index.js',
].filter((entryPoint) => existsSync(entryPoint));

if (entryPoints.length === 0) {
  console.log('No asset entry points found.');
  process.exit(0);
}

const buildOptions = {
  entryPoints,
  bundle: true,
  minify: !isWatch,
  sourcemap: isWatch,
  outdir: 'assets/dist',
  entryNames: '[dir]/[name]',
  assetNames: '[dir]/[name]',
  target: ['es2020'],
  loader: {
    '.css': 'css',
  },
};

if (isWatch) {
  const context = await esbuild.context(buildOptions);
  await context.watch();
  console.log('Watching for changes...');
} else {
  await esbuild.build(buildOptions);
  console.log('Build complete.');
}
