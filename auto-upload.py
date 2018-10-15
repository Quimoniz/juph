#!/usr/bin/python3

from os import listdir, path, mkdir
from time import time, sleep
from shutil import copyfile
import sys

class FileNode:
    def __init__(self, dirpath, filename = ""):
        if 0 < len(filename):
            if 0 < len(dirpath):
                self.path = dirpath + "/" + filename
            else:
                self.path = filename
        else:
            self.path = dirpath
        if 2 < len(self.path) and "./" == self.path[0:2]:
            self.path = self.path[2:]
        self.type = None
        #TODO: maybe bother with symbolic links one day
        if path.isdir(self.path):
            self.type = "d"
        if path.isfile(self.path):
            self.type = "f"
        self.visible = True
        if filename.startswith(".") and "./" != filename and "../" != filename:
            self.visible = False
        self.mtime = 0
        self.utime = 0
        self.update_mtime_if_necessary()

    def update_mtime_if_necessary(self):
        if self.type is not None:
            try:
                new_mtime = int(path.getmtime(self.path))
                if new_mtime != self.mtime:
                    self.mtime = new_mtime
                    return True
            except FileNotFoundError:
                pass
        return False

    def upload(self, target_dir):
        if "f" == self.type:
            target_path = target_dir + "/" + self.path
            print("copying %s → %s" % (self.path, target_path))
            copyfile(self.path, target_path)
        else:
            raise IOError("Can't upload \"%s\", not a regular file" % self.path)
        
    def create(self):
        if path.exists(self.path):
            pass
        else:
            if path.exists(path.dirname(self.path)):
                if "d" == self.type:
                    print("mkdir %s" % self.path)
                    mkdir(self.path)
                elif "f" == self.type:
                    # TODO: do some TOUCH command
                    pass
            else:
                raise IOError("Base path does not exist")



def scan_all_dir(dirpath, descend = True):
    if dirpath is None:
        return []
    if dirpath.endswith('/') and "./" != dirpath and "../" != dirpath:
        dirpath = dirpath[0:-1]
    basepath = dirpath
    if "./" == basepath:
        basepath = ""
    out_file_list = []
    dir_list = []
    out_file_list.append(FileNode(dirpath))

    for curf in listdir(dirpath):
        cur_node = FileNode(basepath, curf)
        if cur_node.visible:
            if "f" == cur_node.type:
                out_file_list.append(cur_node)
            elif "d" == cur_node.type:
                dir_list.append(cur_node)
    if descend:
        for curd in dir_list:
            out_file_list.extend(scan_all_dir(curd.path, descend=True))
    else:
        out_file_list.extend(dir_list)

    return out_file_list


def main(target_dir):
    if target_dir.endswith("/") and "./" != target_dir[len(target_dir)-2:len(target_dir)]:
        target_dir = target_dir[0:-1]
    #first of get a full listing of all files and their last change time
    list_of_files = scan_all_dir("./", descend=True)
    # for curnode in list_of_files: print("%s|%s" % (curnode.mtime, curnode.path))

    #if any of the files have been modified within the last five minutes,
    # then upload them, note that they have been uploaded (note timestamp of upload time)
    # also upload if the file does not exist at the target
    cur_time = time()
    UPDATE_DELAY = 300
    for source_node in list_of_files:
        target_node = FileNode(target_dir, source_node.path)
        if target_node.type is not None:
            if (cur_time - source_node.mtime) < UPDATE_DELAY:
                if "f" == source_node.type:
                    source_node.upload(target_dir)
                elif "d" == source_node.type:
                    if target_node.type is None:
                        target_node.create()
        else:
            if "f" == source_node.type:
                source_node.upload(target_dir)
            elif "d" == source_node.type:
                target_node.create()
        

    #loop, check if:
    # - any file's change time has become bigger than the previously stored timestamp
    #   - if so upload, update timestamps (bot change time and upload time)
    # - any folder has it's change time been changed (indicating a file was created or deleted),
    #   scan folder anew, compare with current list/array of that folder's content
    #   - if file was added, add it to our list
    #     - upload that file (and set timestamps)
    #   - if a file was removed, report so on console, strip it's list/array item
    #     BUT: DO NOT REMOVE THAT FILE ELSEWHERE
    keep_running = True
    SLEEP_DURATION = 3
    while keep_running:
        try:
            i = 0
            while i < len(list_of_files):
                source_node = list_of_files[i]
                if source_node.update_mtime_if_necessary():
                    if "f" == source_node.type:
                        source_node.upload(target_dir)
                    elif "d" == source_node.type:
                        # only check for added or removed files/directories in current dir
                        curdir_unchecked = scan_all_dir(source_node.path, descend=False)
                        del curdir_unchecked[0]
                        curdir_old = {}
                        for j in range(i + 1, len(list_of_files)):
                            sub_node = list_of_files[j]
                            if path.dirname(sub_node.path) == source_node.path or ("" == path.dirname(sub_node.path) and "./" == source_node.path):
                                curdir_old[sub_node.path] = j
                        j = 0
                        while j < len(curdir_unchecked):
                            if curdir_unchecked[j].path in curdir_old:
                                del curdir_old[curdir_unchecked[j].path]
                                del curdir_unchecked[j]
                            else:
                                j = j + 1
                        #TODO: add support for removing and adding from list recursively
                        #now removed files are in curdir_old
                        if 0 < len(curdir_old):
                            list_of_keys = []
                            for curf in curdir_old.keys():
                                list_of_keys.append(curf)
                            j = len(list_of_keys) - 1
                            while j >= 0:
                                print("detected file removal %s not doing anything" % list_of_keys[j])
                                del list_of_files[curdir_old[list_of_keys[j]]]
                                j = j - 1
                        #now added files are in curdir_unchecked
                        if 0 < len(curdir_unchecked):
                            for sub_node in curdir_unchecked:
                                print("added %s" % sub_node.path)
                                sub_node.upload(target_dir)
                                list_of_files.insert(i + 1, sub_node)
                       
                i = i + 1
            sleep(SLEEP_DURATION)
        except KeyboardInterrupt:
            keep_running = False




if __name__ == "__main__":
  if len(sys.argv) > 1:
      if path.exists(sys.argv[1]):
          main(sys.argv[1])
      else:
          print("Specified path does not exist (%s)" % sys.argv[1])
  else:
      print("Target Dir required")
