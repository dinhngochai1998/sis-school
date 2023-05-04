#!/bin/bash

# $1 means now version (currently in-SNAPSHOT and to be promoted), for example 1.2.3
# $2 means new version (next -in SNAPSHOT), for example 1.2.4

DEVELOPER_BRANCH="dev"
VIP_BRANCHES=("stg" "prd")
VERSION_CONTAINER_FILE="composer.json"
SNAPSHOT_PATTERN="\"version\": \"$1-dev\""
RELEASE_PATTERN="\"version\": \"$1\""

function instruction() {
  echo "----------------------------------------🧑‍💻 Script Maintainer: 7longtran@gmail.com ------------------------------------------"
  echo "😉  This promote script takes EXACTLY 2️⃣  non-empty arguments."
  echo "✨  First arg should be current in-SNAPSHOT version, for example 1.2.9. Second arg should be next version, for example 1.3.0"
  echo "🤨  This is NOT a joke, please be sure you know what you are doing and TYPE CAREFULLY !";
  echo "----------------------------------------------------------------------------------------------------------------------------"
}

instruction;
if [ $# -ne 2 ] || [ -z "$1" ] || [ -z "$2" ]
  then
    echo "⚠️  Invalid arguments"; exit 1
fi

echo "👁️  Looking for $SNAPSHOT_PATTERN in 👉 $VERSION_CONTAINER_FILE 👈"
if ! grep -q "$SNAPSHOT_PATTERN" $VERSION_CONTAINER_FILE;
  then
    echo "⚠️  Cannot really find $SNAPSHOT_PATTERN in $VERSION_CONTAINER_FILE "; exit 1
fi

GIT_PUSH_REMOTE_COMMANDS=""
GIT_REVERT_LOCAL_COMMANDS="git checkout -b dummy;"
function git_action() {
  git config pull.rebase true
  git fetch origin; git checkout $DEVELOPER_BRANCH
  if ! [ -z "$(git status --porcelain)" ]
    then
      echo "👇 You have uncommitted changes, please deal with them first."; git status; exit 1;
  fi
  git pull origin $DEVELOPER_BRANCH;
  git branch -D release-"$1";
  git checkout -b release-"$1"
  sed -i.bak "1,/$SNAPSHOT_PATTERN/s/$1-dev/$1/" $VERSION_CONTAINER_FILE
  git add $VERSION_CONTAINER_FILE
  git commit -m "release $1"

  for VIP_BRANCH in "${VIP_BRANCHES[@]}"
  do
    git branch -D "$VIP_BRANCH" ; git checkout "$VIP_BRANCH"
    git merge --no-ff release-"$1" -m "Merge branch release-$1 into $VIP_BRANCH"
    GIT_PUSH_REMOTE_COMMANDS="$GIT_PUSH_REMOTE_COMMANDS git checkout $VIP_BRANCH; git push origin $VIP_BRANCH;"
    GIT_REVERT_LOCAL_COMMANDS="$GIT_REVERT_LOCAL_COMMANDS git branch -D $VIP_BRANCH;"
  done

  git tag -d "$1"
  git tag -a "$1" -m "Release $1"
  git checkout $DEVELOPER_BRANCH
  git merge --no-ff release-"$1" -m "Merge branch release-$1 into $DEVELOPER_BRANCH"
  sed -i.bak "1,/$RELEASE_PATTERN/s/$1/$2-dev/" $VERSION_CONTAINER_FILE
  git add $VERSION_CONTAINER_FILE
  git commit -m "New Cycle $2-dev"
  git branch -D release-"$1"
  rm $VERSION_CONTAINER_FILE.bak
  GIT_PUSH_REMOTE_COMMANDS="$GIT_PUSH_REMOTE_COMMANDS git checkout $DEVELOPER_BRANCH; git push origin $DEVELOPER_BRANCH; git push --tags;"
  GIT_REVERT_LOCAL_COMMANDS="$GIT_REVERT_LOCAL_COMMANDS git branch -D $DEVELOPER_BRANCH; git checkout $DEVELOPER_BRANCH; git branch -D dummy;"
}

echo "🚥 Let there be light"
echo "..."
git_action "$@"
echo "🚦 All is well."
echo "---------------------------------------------------------------------------------------------------------------------------"
# shellcheck disable=SC2145
echo "🙏  Please REVIEW 🌀 ${VIP_BRANCHES[@]} 🌀 before syncing with remote branches"
echo
echo "🌧️️  After reviewing, if things go BAD you can revert local git repo as follows:"
echo "☔  $GIT_REVERT_LOCAL_COMMANDS ☔"
echo
echo "☀️  After reviewing, if things go WELL you can sync with the remote git repo as follows:"
echo "😎  $GIT_PUSH_REMOTE_COMMANDS 😎"
echo "---------------------------------------------------------------------------------------------------------------------------"
