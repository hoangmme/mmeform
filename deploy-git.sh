#!/bin/bash

# Simple script to deploy mmeform to git
echo "Deploying to Git..."

# Add all changes
git add .

# Commit with timestamp
git commit -m "deploy: update $(date +'%Y-%m-%d %H:%M:%S')"

# Push to origin
git push

echo "Done!"
